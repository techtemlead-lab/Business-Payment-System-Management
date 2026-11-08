<?php

namespace App\Domain\Clinic;

use App\Domain\Geography\PlaceTree;
use App\Models\PlaceCode;
use App\Models\PrescriberPublicProfile;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The public list of registered prescribers. Someone appears only while prescribing is on for
 * them and they have chosen to be listed. Only the fields on a directory row ever leave this
 * class, so a phone number or profile detail cannot reach the page.
 */
final class PrescriberDirectory
{
    public const PerPage = 12;

    /** The place-code levels offered as filters, top first. */
    public const FilterLevels = [PlaceCode::LevelDivision, PlaceCode::LevelDistrict, PlaceCode::LevelUpazila, PlaceCode::LevelUnion];

    /** Bangla digits to plain ones: visitors and prescribers may type either. */
    public const BanglaDigits = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];

    /** Nine digits in a row is a phone number; chamber times never reach that. */
    private const PhoneLikeDigits = 9;

    /**
     * Prescribers who may be shown right now. Every public read goes through this query, so
     * turning prescribing off removes the person at once.
     *
     * @return Builder<User>
     */
    public function listedPrescribers(): Builder
    {
        return User::query()
            ->join('prescriber_registrations as registration', function (JoinClause $join): void {
                $join->on('registration.user_id', '=', 'users.id')->where('registration.enabled', true);
            })
            ->join('prescriber_public_profiles as listing', function (JoinClause $join): void {
                $join->on('listing.user_id', '=', 'users.id')->where('listing.listed', true);
            })
            ->where('users.status', 'active')
            ->where(function (Builder $query): void {
                $query->where('users.role', 'super_admin')->orWhere('users.can_prescribe', true);
            });
    }

    /** The listed prescriber behind a public listing number, or null when not listed now. */
    public function findListed(int $listingId): ?User
    {
        return $this->listedPrescribers()
            ->where('listing.id', $listingId)
            ->select(['users.id', 'users.name', 'users.locale'])
            ->first();
    }

    /**
     * One page of the directory, narrowed to a place from the chosen place-code levels.
     *
     * @param  array<string, mixed>  $filters  Chosen place code ids, keyed by level.
     * @return array{rows: LengthAwarePaginator<int, array{listing_id: int, name: string, registration_type: string, registration_number: string, area: string|null, chamber_hours: string|null}>, levels: list<array{level: string, selected: int|null, options: list<array{id: int, label: string}>}>}
     */
    public function page(array $filters): array
    {
        [$levels, $placeId] = $this->filterLevels($filters);
        $query = $this->listedPrescribers();
        if ($placeId !== null) {
            PlaceTree::whereInside($query, [$placeId]);
        }
        $paginator = $query
            ->select(['listing.id as listing_id', 'users.name', 'registration.registration_type', 'registration.registration_number', 'listing.chamber_hours'])
            ->selectRaw(PlaceTree::partnerPlaceIdSql('users').' AS place_id')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate(self::PerPage)
            ->withQueryString();
        $places = PlaceCode::query()
            ->whereIn('id', collect($paginator->items())->pluck('place_id')->filter()->all())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        return [
            'rows' => $paginator->through(fn (User $row): array => [
                'listing_id' => (int) $row->getAttribute('listing_id'),
                'name' => (string) $row->name,
                'registration_type' => (string) $row->getAttribute('registration_type'),
                'registration_number' => (string) $row->getAttribute('registration_number'),
                'area' => ($place = $places->get((int) $row->getAttribute('place_id'))) ? $this->placeLabel($place->code, $place->name) : null,
                'chamber_hours' => $row->getAttribute('chamber_hours'),
            ]),
            'levels' => $levels,
        ];
    }

    /**
     * The prescriber's own choice, for their settings tab.
     *
     * @return array{listed: bool, chamber_hours: string|null, registered: bool}
     */
    public function listingFor(User $prescriber): array
    {
        $listing = PrescriberPublicProfile::query()->where('user_id', $prescriber->id)->first();

        return [
            'listed' => (bool) $listing?->listed,
            'chamber_hours' => $listing?->chamber_hours,
            'registered' => DB::table('prescriber_registrations')->where('user_id', $prescriber->id)->where('enabled', true)->exists(),
        ];
    }

    /**
     * @param  array{listed: bool|int|string, chamber_hours?: string|null}  $data
     */
    public function saveListing(User $prescriber, array $data): PrescriberPublicProfile
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);
        $hours = trim((string) ($data['chamber_hours'] ?? ''));
        if ($this->looksLikePhone($hours)) {
            throw ValidationException::withMessages(['chamber_hours' => __('prescriber_public.err_hours_phone')]);
        }
        $listed = filter_var($data['listed'], FILTER_VALIDATE_BOOLEAN);

        return DB::transaction(function () use ($prescriber, $hours, $listed): PrescriberPublicProfile {
            $profile = PrescriberPublicProfile::query()->updateOrCreate(['user_id' => $prescriber->id], [
                'listed' => $listed,
                'chamber_hours' => $hours === '' ? null : $hours,
            ]);
            Audit::record($prescriber->id, $listed ? 'prescriber.public_listing_on' : 'prescriber.public_listing_off', (string) $prescriber->id, []);

            return $profile;
        });
    }

    /**
     * The picker for each level, offering only places that hold a listed prescriber. A level
     * opens once the one above it has a valid choice; the deepest valid choice filters the list.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: list<array{level: string, selected: int|null, options: list<array{id: int, label: string}>}>, 1: int|null}
     */
    private function filterLevels(array $filters): array
    {
        $places = $this->placesHoldingPrescribers();
        $levels = [];
        $parentId = null;
        foreach (self::FilterLevels as $level) {
            $options = [];
            foreach ($places as $place) {
                if ($place->level === $level && ($parentId === null || (int) $place->parent_id === $parentId)) {
                    $options[] = ['id' => (int) $place->id, 'label' => $this->placeLabel($place->code, $place->name)];
                }
            }
            if ($options === []) {
                break;
            }
            $chosen = PlaceTree::idFrom($filters[$level] ?? null);
            $selected = $chosen !== null && in_array($chosen, array_column($options, 'id'), true) ? $chosen : null;
            $levels[] = ['level' => $level, 'selected' => $selected, 'options' => $options];
            if ($selected === null) {
                break;
            }
            $parentId = $selected;
        }

        return [$levels, $parentId];
    }

    /**
     * Listed prescribers' places and every place above them.
     *
     * @return list<object{id: int, parent_id: int|null, level: string, code: string|null, name: string}>
     */
    private function placesHoldingPrescribers(): array
    {
        $starts = $this->listedPrescribers()->select(DB::raw(PlaceTree::partnerPlaceIdSql('users').' AS place_id'))->toBase();

        return DB::select('WITH RECURSIVE starts AS ('.$starts->toSql().'), chain AS (
                SELECT place.id, place.parent_id, place.level, place.code, place.name
                FROM place_codes AS place
                WHERE place.id IN (SELECT place_id FROM starts)
                UNION
                SELECT parent.id, parent.parent_id, parent.level, parent.code, parent.name
                FROM chain
                JOIN place_codes AS parent ON parent.id = chain.parent_id
            )
            SELECT id, parent_id, level, code, name FROM chain ORDER BY code, name', $starts->getBindings());
    }

    private function placeLabel(?string $code, ?string $name): string
    {
        return trim(PlaceCode::displayLabel($code).' — '.PlaceCode::displayLabel($name));
    }

    private function looksLikePhone(string $text): bool
    {
        $joined = (string) preg_replace('/[\s\-().+]/u', '', strtr($text, self::BanglaDigits));

        return preg_match('/\d{'.self::PhoneLikeDigits.',}/', $joined) === 1;
    }
}
