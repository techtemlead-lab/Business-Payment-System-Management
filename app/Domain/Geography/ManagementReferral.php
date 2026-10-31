<?php

namespace App\Domain\Geography;

use App\Models\CommissionAllocation;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Company-owned referrer synchronized with the management place code.
 *
 * The code is not held by a person. Blank partner references and an explicit
 * management code both point here. Direct referral (10%), D2D generation 2 (2%),
 * D2D generation 3 (1%), and the area-pool management share (2%) post into the
 * existing management reserve (`company_management_area_share` / treasury
 * `management_reserve_2`).
 */
final class ManagementReferral
{
    public const SettingKey = 'management_referral';

    public const FundRetainedBy = 'company_management_area_share';

    public const ProfileFlag = 'management';

    /**
     * @return array{
     *   enabled: bool,
     *   auto_blank_reference: bool,
     *   route_referral_slices: bool,
     *   version: int,
     *   place_code_id: ?int,
     *   place_code: ?string,
     *   place_name: ?string,
     *   fund: string,
     *   options: list<array{id: int, code: string, name: string}>
     * }
     */
    public function settings(): array
    {
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = [];
        if ($row && is_string($row->value) && $row->value !== '') {
            $decoded = json_decode($row->value, true);
            $stored = is_array($decoded) ? $decoded : [];
        }
        $preferred = isset($stored['place_code_id']) ? (int) $stored['place_code_id'] : null;
        $place = $this->resolvePlace($preferred);

        return [
            'enabled' => (bool) ($stored['enabled'] ?? true),
            'auto_blank_reference' => (bool) ($stored['auto_blank_reference'] ?? true),
            'route_referral_slices' => (bool) ($stored['route_referral_slices'] ?? true),
            'version' => (int) ($row->version ?? 0),
            'place_code_id' => $place?->id,
            'place_code' => $place?->code,
            'place_name' => $place?->name,
            'fund' => self::FundRetainedBy,
            'options' => $this->placeOptions(),
        ];
    }

    /**
     * @param  array{enabled: bool, auto_blank_reference: bool, route_referral_slices: bool, place_code_id: ?int, version: int}  $input
     */
    public function save(User $actor, array $input): void
    {
        DB::transaction(function () use ($actor, $input): void {
            abort_unless($actor->fresh()->owner(), 403);
            $before = $this->settings();
            if ((int) $input['version'] !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.pc_mgmt_stale')]);
            }
            $placeId = $input['place_code_id'] ?? null;
            if ($placeId !== null) {
                $place = PlaceCode::query()->whereKey($placeId)->first();
                if (! $place || ! $place->isManagement() || ! $place->isReadyForAssignment()) {
                    throw ValidationException::withMessages(['place_code_id' => __('portal.pc_mgmt_place_invalid')]);
                }
            }
            $after = [
                'enabled' => (bool) $input['enabled'],
                'auto_blank_reference' => (bool) $input['auto_blank_reference'],
                'route_referral_slices' => (bool) $input['route_referral_slices'],
                'place_code_id' => $placeId,
            ];
            $existing = DB::table('business_settings')->where('key', self::SettingKey)->first();
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $before['version'] + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'place_codes.management_referral_updated', self::SettingKey, [
                'before' => [
                    'enabled' => $before['enabled'],
                    'auto_blank_reference' => $before['auto_blank_reference'],
                    'route_referral_slices' => $before['route_referral_slices'],
                    'place_code_id' => $before['place_code_id'],
                ],
                'after' => $after,
            ]);
        }, 3);
    }

    public function matchesReference(string $code): bool
    {
        $settings = $this->settings();
        if (! $settings['enabled'] || $settings['place_code'] === null) {
            return false;
        }
        $normalized = PlaceCode::normalizeCode($code);
        $canonical = PlaceCode::normalizeCode($settings['place_code']);

        return $normalized !== null && $canonical !== null && $normalized === $canonical;
    }

    public function countsAsManagement(User $user): bool
    {
        $settings = $this->settings();
        if (! $settings['enabled'] || $settings['place_code_id'] === null) {
            return false;
        }
        if (! in_array($user->role, ['dealer', 'sub_dealer'], true) || $user->sponsor_id) {
            return false;
        }
        $profile = is_array($user->profile) ? $user->profile : [];
        if (($profile['referred_by'] ?? null) === self::ProfileFlag) {
            return true;
        }

        return $settings['auto_blank_reference'];
    }

    /**
     * First referral generation (1 = direct 10%) that belongs to the management fund.
     * Null keeps the historical unassigned-reserve path.
     */
    public function fundFromGeneration(User $buyer): ?int
    {
        $settings = $this->settings();
        if (! $settings['enabled'] || ! $settings['route_referral_slices'] || $settings['place_code_id'] === null) {
            return null;
        }
        if (! in_array($buyer->role, ['dealer', 'sub_dealer'], true)) {
            return null;
        }
        $cursor = $buyer;
        for ($generation = 1; $generation <= 3; $generation++) {
            if (! $cursor->sponsor_id) {
                return $this->countsAsManagement($cursor) ? $generation : null;
            }
            $next = User::query()->find($cursor->sponsor_id);
            if (! $next) {
                return null;
            }
            $cursor = $next;
        }

        return null;
    }

    /**
     * Record that a partner with no personal sponsor is referred by the management code.
     */
    public function stamp(User $user): void
    {
        if (! in_array($user->role, ['dealer', 'sub_dealer'], true)) {
            return;
        }
        if ($user->sponsor_id) {
            $this->clear($user);

            return;
        }
        $settings = $this->settings();
        if (! $settings['enabled'] || ! $settings['auto_blank_reference'] || $settings['place_code'] === null) {
            return;
        }
        $profile = is_array($user->profile) ? $user->profile : [];
        $profile['referred_by'] = self::ProfileFlag;
        $profile['management_refer_code'] = $settings['place_code'];
        $profile['management_place_code_id'] = $settings['place_code_id'];
        $user->forceFill(['profile' => $profile])->save();
    }

    public function clear(User $user): void
    {
        $profile = is_array($user->profile) ? $user->profile : [];
        if (($profile['referred_by'] ?? null) !== self::ProfileFlag) {
            return;
        }
        unset($profile['referred_by'], $profile['management_refer_code'], $profile['management_place_code_id']);
        $user->forceFill(['profile' => $profile])->save();
    }

    public function fundAmount(): string
    {
        $sum = CommissionAllocation::query()
            ->where('status', '!=', 'reversed')
            ->where(function ($query): void {
                $query->where('category', 'area_management')
                    ->orWhere('metadata->retained_by', self::FundRetainedBy);
            })
            ->sum('amount');

        return number_format((float) $sum, 2, '.', '');
    }

    public function belongsToFund(CommissionAllocation $allocation): bool
    {
        $metadata = is_array($allocation->metadata) ? $allocation->metadata : [];

        return $allocation->category === 'area_management'
            || ($metadata['retained_by'] ?? null) === self::FundRetainedBy;
    }

    /** @return list<array{id: int, code: string, name: string}> */
    private function placeOptions(): array
    {
        return PlaceCode::query()
            ->where('level', PlaceCode::LevelManagement)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (PlaceCode $place): bool => $place->isReadyForAssignment())
            ->map(fn (PlaceCode $place): array => [
                'id' => $place->id,
                'code' => (string) $place->code,
                'name' => (string) $place->name,
            ])
            ->values()
            ->all();
    }

    private function resolvePlace(?int $preferredId): ?PlaceCode
    {
        if ($preferredId) {
            $preferred = PlaceCode::query()->whereKey($preferredId)->first();
            if ($preferred && $preferred->isManagement() && $preferred->isReadyForAssignment()) {
                return $preferred;
            }
        }

        return PlaceCode::query()
            ->where('level', PlaceCode::LevelManagement)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->first(fn (PlaceCode $place): bool => $place->isReadyForAssignment());
    }
}
