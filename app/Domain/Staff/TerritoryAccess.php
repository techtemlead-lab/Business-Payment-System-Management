<?php

namespace App\Domain\Staff;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Notifications\PartnerEventSms;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TerritoryAccess
{
    public const Fields = ['division', 'district', 'upazila', 'union'];

    /** @return list<array<string, mixed>> */
    public function territories(int $staffId): array
    {
        return DB::table('staff_territories')->where('staff_id', $staffId)->where('is_active', true)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    public function partners(User $actor): Builder
    {
        $query = User::whereIn('role', ['dealer', 'sub_dealer']);
        if ($actor->allows('staff_admin')) {
            return $query;
        }
        abort_unless($actor->allows('field_sales'), 403);
        $regions = $this->territories($actor->id);
        if ($regions === []) {
            return $query->whereRaw('1=0');
        }

        return $query->where(function (Builder $outer) use ($regions): void {
            foreach ($regions as $region) {
                $outer->orWhere(function (Builder $inner) use ($region): void {
                    foreach (self::Fields as $field) {
                        $column = in_array($field, ['division', 'district'], true) ? 'users.'.$field : "users.profile->>'".$field."'";
                        $inner->whereRaw('LOWER(TRIM('.$column.')) = ?', [mb_strtolower(trim($region[$field]))]);
                        if ($field === $region['level']) {
                            break;
                        }
                    }
                });
            }
        });
    }

    public function assertPartner(User $actor, int $userId, string $capability): User
    {
        $actor = $actor->fresh();
        abort_unless($actor->allows($capability), 403);

        return $this->partners($actor)->whereKey($userId)->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    public function assertGeography(User $actor, array $data): void
    {
        if ($actor->allows('staff_admin')) {
            return;
        }
        foreach ($this->territories($actor->id) as $region) {
            $matches = true;
            foreach (self::Fields as $field) {
                $matches = $matches && mb_strtolower(trim((string) ($data[$field] ?? ''))) === mb_strtolower(trim($region[$field]));
                if ($field === $region['level']) {
                    break;
                }
            }
            if ($matches) {
                return;
            }
        }
        abort(403, __('portal.err_terr_outside'));
    }

    /** @param array<string, mixed> $data */
    public function assign(User $actor, array $data): int
    {
        abort_unless($actor->allows('staff_admin'), 403);

        return DB::transaction(function () use ($actor, $data): int {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('staff_admin'), 403);
            $staff = User::findOrFail($data['staff_id']);
            if (! $staff->staff() || ! $staff->allows('field_sales') || $staff->id === $actor->id) {
                throw ValidationException::withMessages(['staff_id' => __('portal.err_terr_sr')]);
            }
            $path = [];
            foreach (self::Fields as $field) {
                $value = trim((string) ($data[$field] ?? ''));
                if ($value === '') {
                    throw ValidationException::withMessages([$field => __('portal.err_terr_hierarchy')]);
                }
                $path[$field] = $value;
                if ($field === $data['level']) {
                    break;
                }
            }
            $key = hash('sha256', json_encode([$staff->id, $data['level'], array_map(mb_strtolower(...), $path)], JSON_THROW_ON_ERROR));
            $existing = DB::table('staff_territories')->where('territory_key', $key)->first();
            if ($existing) {
                return $existing->id;
            }
            $id = DB::table('staff_territories')->insertGetId([...$path, 'staff_id' => $staff->id, 'level' => $data['level'], 'territory_key' => $key,
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'staff.territory_assigned', (string) $id, ['staff_id' => $staff->id]);
            $locale = $staff->preferredLocale();
            $label = collect($path)->filter()->implode(' / ');
            Audit::notice(
                $staff->id,
                __('portal.notice_staff_territory_title', [], $locale),
                __('portal.notice_staff_territory_body', [
                    'level' => $data['level'],
                    'territory' => $label,
                ], $locale),
            );
            DB::afterCommit(function () use ($staff, $locale, $data, $label): void {
                app(PartnerEventSms::class)->send(
                    $staff,
                    'staff.territory_assigned',
                    __('sms.staff_territory_assigned', [
                        'level' => $data['level'],
                        'territory' => $label,
                    ], $locale),
                );
            });

            return $id;
        }, 3);
    }

    /** @param list<array<string, mixed>>|null $regions */
    public function volume(int $staffId, string $month, ?array $regions = null): int
    {
        CommissionQualification::period($month);
        $regions ??= $this->territories($staffId);
        if ($regions === []) {
            return 0;
        }
        $query = DB::table('commission_batches')->where('period_month', $month);
        $query->where(function ($outer) use ($regions): void {
            foreach ($regions as $region) {
                $outer->orWhere(function ($inner) use ($region): void {
                    foreach (self::Fields as $field) {
                        $inner->whereRaw("LOWER(TRIM(policy->'geography'->>?)) = ?", [$field, mb_strtolower(trim($region[$field]))]);
                        if ($field === $region['level']) {
                            break;
                        }
                    }
                });
            }
        });

        return Money::cents((string) $query->selectRaw('COALESCE(SUM(cash_basis - refunded_amount),0) AS volume')->value('volume'));
    }
}
