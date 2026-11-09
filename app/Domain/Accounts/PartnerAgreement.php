<?php

namespace App\Domain\Accounts;

use App\Models\PartnerAgreementAcceptance;
use App\Models\PartnerAgreementVersion;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The dealer agreement partners accept on screen.
 *
 * The owner publishes versions (each one kept for good) and decides with one
 * switch whether partners must accept the latest version before they can use
 * their portal. The switch is off until the owner turns it on.
 */
final class PartnerAgreement
{
    /** business_settings key for the "partners must accept" switch. */
    public const SettingKey = 'partner_agreement';

    /** Request attribute that keeps this request's status, so it is read once. */
    public const RequestAttribute = 'partner_agreement_status';

    public const PartnerRoles = ['dealer', 'sub_dealer'];

    /**
     * Whether the switch is on, the latest version, and whether this partner
     * accepted it — all from one query, because it runs on every partner page.
     *
     * @return array{required: bool, version_id: int|null, version: int|null, accepted: bool}
     */
    public function status(User $user): array
    {
        $row = DB::selectOne(<<<'SQL'
            select setting.value as setting,
                latest.id as version_id,
                latest.version as version,
                exists(
                    select 1 from partner_agreement_acceptances accepted
                    where accepted.partner_agreement_version_id = latest.id and accepted.user_id = ?
                ) as accepted
            from (select 1) as one
            left join business_settings setting on setting.key = ?
            left join lateral (
                select id, version from partner_agreement_versions order by version desc limit 1
            ) latest on true
            SQL, [$user->id, self::SettingKey]);

        $setting = is_string($row?->setting ?? null) ? json_decode($row->setting, true, 512, JSON_THROW_ON_ERROR) : [];

        return [
            'required' => (bool) ($setting['required'] ?? false),
            'version_id' => isset($row->version_id) ? (int) $row->version_id : null,
            'version' => isset($row->version) ? (int) $row->version : null,
            'accepted' => (bool) ($row->accepted ?? false),
        ];
    }

    /**
     * True when this partner must accept the latest version before using the portal.
     *
     * @param  array{required: bool, version_id: int|null, version: int|null, accepted: bool}  $status
     */
    public function blocks(User $user, array $status): bool
    {
        return in_array($user->role, self::PartnerRoles, true)
            && $status['required']
            && $status['version_id'] !== null
            && ! $status['accepted'];
    }

    /**
     * The owner switch "Partners must accept the agreement". Off by default.
     *
     * @return array{required: bool, version: int}
     */
    public function setting(): array
    {
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];

        return ['required' => (bool) ($stored['required'] ?? false), 'version' => (int) ($row->version ?? 0)];
    }

    public function saveSetting(User $actor, bool $required, int $version): void
    {
        DB::transaction(function () use ($actor, $required, $version): void {
            abort_unless($actor->fresh()->owner(), 403);
            $before = $this->setting();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.agr_err_stale_setting')]);
            }
            $stored = DB::table('business_settings')->where('key', self::SettingKey)->first();
            $after = ['required' => $required];
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $stored?->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'agreement.requirement_changed', self::SettingKey, [
                'before' => ['required' => $before['required']],
                'after' => $after,
            ]);
        }, 3);
    }

    public function latest(): ?PartnerAgreementVersion
    {
        return PartnerAgreementVersion::query()->orderByDesc('version')->first();
    }

    /**
     * Every published version, newest first, with who published it.
     *
     * @return list<array{id: int, version: int, body_bn: string, body_en: string, published_at: string|null, published_by: string|null, acceptances: int}>
     */
    public function history(): array
    {
        return PartnerAgreementVersion::query()
            ->with('publisher:id,name')
            ->withCount('acceptances')
            ->orderByDesc('version')
            ->get()
            ->map(fn (PartnerAgreementVersion $version): array => [
                'id' => (int) $version->id,
                'version' => $version->version,
                'body_bn' => $version->body_bn,
                'body_en' => $version->body_en,
                'published_at' => $version->published_at?->toIso8601String(),
                'published_by' => $version->publisher?->name,
                'acceptances' => (int) $version->acceptances_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Publish a new version. The old versions stay as they were.
     */
    public function publish(User $actor, string $bodyBn, string $bodyEn): PartnerAgreementVersion
    {
        return DB::transaction(function () use ($actor, $bodyBn, $bodyEn): PartnerAgreementVersion {
            abort_unless($actor->fresh()->owner(), 403);
            // Two owners publishing at once must not get the same version number.
            DB::statement('LOCK TABLE partner_agreement_versions IN SHARE ROW EXCLUSIVE MODE');
            $previous = $this->latest();
            if ($previous !== null && trim($previous->body_bn) === trim($bodyBn) && trim($previous->body_en) === trim($bodyEn)) {
                throw ValidationException::withMessages(['body_bn' => __('portal.err_agr_unchanged')]);
            }
            $version = PartnerAgreementVersion::query()->create([
                'version' => ($previous?->version ?? 0) + 1,
                'body_bn' => trim($bodyBn),
                'body_en' => trim($bodyEn),
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);
            Audit::record($actor->id, 'agreement.published', 'partner_agreement_version:'.$version->id, [
                'version' => $version->version,
                'previous_version' => $previous?->version,
                'body_bn_sha256' => hash('sha256', $version->body_bn),
                'body_en_sha256' => hash('sha256', $version->body_en),
            ]);

            return $version;
        }, 3);
    }

    /**
     * Record that the partner read and agreed to the latest version. Pressing
     * the button twice keeps the first record.
     */
    public function accept(User $user, int $versionId, ?string $ipAddress, ?string $userAgent): PartnerAgreementAcceptance
    {
        abort_unless(in_array($user->role, self::PartnerRoles, true), 403);

        return DB::transaction(function () use ($user, $versionId, $ipAddress, $userAgent): PartnerAgreementAcceptance {
            $latest = $this->latest();
            if ($latest === null || (int) $latest->id !== $versionId) {
                throw ValidationException::withMessages(['version' => __('portal.agr_err_new_version')]);
            }
            $acceptance = PartnerAgreementAcceptance::query()->createOrFirst([
                'user_id' => $user->id,
                'partner_agreement_version_id' => $latest->id,
            ], [
                'accepted_at' => now(),
                'ip_address' => $ipAddress !== null ? Str::limit($ipAddress, 45, '') : null,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
            ]);
            if (! $acceptance->wasRecentlyCreated) {
                return $acceptance;
            }
            Audit::record($user->id, 'agreement.accepted', 'partner_agreement_version:'.$latest->id, [
                'version' => $latest->version,
                'ip_address' => $acceptance->ip_address,
            ]);

            return $acceptance;
        }, 3);
    }

    /**
     * When this partner accepted each version, newest first.
     *
     * @return list<array{version: int, accepted_at: string|null}>
     */
    public function acceptedBy(User $user): array
    {
        return PartnerAgreementAcceptance::query()
            ->join('partner_agreement_versions', 'partner_agreement_versions.id', '=', 'partner_agreement_acceptances.partner_agreement_version_id')
            ->where('partner_agreement_acceptances.user_id', $user->id)
            ->orderByDesc('partner_agreement_versions.version')
            ->get(['partner_agreement_versions.version', 'partner_agreement_acceptances.accepted_at'])
            ->map(fn (PartnerAgreementAcceptance $row): array => [
                'version' => (int) $row->version,
                'accepted_at' => $row->accepted_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Dealers and sub-dealers with the newest version each one accepted, for
     * the office list. One query for the page, however many partners there are.
     */
    public function partnerList(?string $search, int $perPage = 25): LengthAwarePaginator
    {
        $latestAcceptance = DB::table('partner_agreement_acceptances')
            ->join('partner_agreement_versions', 'partner_agreement_versions.id', '=', 'partner_agreement_acceptances.partner_agreement_version_id')
            ->whereColumn('partner_agreement_acceptances.user_id', 'users.id')
            ->orderByDesc('partner_agreement_versions.version')
            ->limit(1);

        return DB::table('users')
            ->whereIn('users.role', self::PartnerRoles)
            ->when(filled($search), function ($query) use ($search): void {
                $term = '%'.trim((string) $search).'%';
                $query->where(fn ($match) => $match
                    ->where('users.name', 'ilike', $term)
                    ->orWhere('users.dealer_code', 'ilike', $term)
                    ->orWhere('users.phone', 'ilike', $term));
            })
            ->select(['users.id', 'users.name', 'users.dealer_code', 'users.role', 'users.status'])
            ->selectSub((clone $latestAcceptance)->select('partner_agreement_versions.version'), 'accepted_version')
            ->selectSub((clone $latestAcceptance)->select('partner_agreement_acceptances.accepted_at'), 'accepted_at')
            ->selectSub((clone $latestAcceptance)->select('partner_agreement_acceptances.ip_address'), 'ip_address')
            ->orderBy('users.name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * How many partners accepted the latest version, out of all active partners.
     *
     * @return array{partners: int, accepted_latest: int}
     */
    public function summary(?int $latestVersionId): array
    {
        $row = DB::table('users')
            ->whereIn('role', self::PartnerRoles)
            ->where('status', 'active')
            ->selectRaw('count(*) as partners')
            ->selectRaw(
                'count(*) filter (where exists (select 1 from partner_agreement_acceptances a where a.user_id = users.id and a.partner_agreement_version_id = ?)) as accepted_latest',
                [$latestVersionId ?? 0],
            )
            ->first();

        return ['partners' => (int) ($row->partners ?? 0), 'accepted_latest' => (int) ($row->accepted_latest ?? 0)];
    }
}
