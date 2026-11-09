<?php

namespace App\Domain\Staff;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Geography\PlaceTree;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Models\PlaceCode;
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

    /**
     * The place code ids of a staff member's territories. A territory saved without a
     * place code gives no access: territories are never matched by place name.
     *
     * @param  list<array<string, mixed>>|null  $regions
     * @return list<int>
     */
    public function placeIds(int $staffId, ?array $regions = null): array
    {
        $regions ??= $this->territories($staffId);
        $ids = [];
        foreach ($regions as $region) {
            $id = filter_var($region['place_code_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Partners a staff member may work with: everyone for staff admins; for a field rep,
     * partners whose place code is one of their territory places or lies inside one.
     */
    public function partners(User $actor): Builder
    {
        $query = User::whereIn('role', ['dealer', 'sub_dealer']);
        if ($actor->allows('staff_admin')) {
            return $query;
        }
        abort_unless($actor->allows('field_sales'), 403);
        PlaceTree::whereInside($query, $this->placeIds($actor->id));

        return $query;
    }

    public function assertPartner(User $actor, int $userId, string $capability): User
    {
        $actor = $actor->fresh();
        abort_unless($actor->allows($capability), 403);

        return $this->partners($actor)->whereKey($userId)->firstOrFail();
    }

    /**
     * Stop a field rep from working outside their territories: the place must be one of
     * their territory places or lie inside one, compared by place code id.
     */
    public function assertPlace(User $actor, PlaceCode $place): void
    {
        if ($actor->allows('staff_admin')) {
            return;
        }
        if (! app(PlaceTree::class)->isInside((int) $place->id, $this->placeIds($actor->id))) {
            abort(403, __('portal.err_terr_outside'));
        }
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
            // A territory is a place code; its names are filled in from that code for display.
            $place = PlaceTree::requirePlace($data['place_code'] ?? null);
            if (filled($data['level'] ?? null) && $data['level'] !== $place->level) {
                throw ValidationException::withMessages(['place_code' => __('portal.cmd_val_place_level_mismatch')]);
            }
            $path = app(PlaceTree::class)->namesDownTo($place);
            $key = hash('sha256', json_encode([$staff->id, 'place', (int) $place->id], JSON_THROW_ON_ERROR));
            $existing = DB::table('staff_territories')->where('territory_key', $key)->first();
            if ($existing) {
                return $existing->id;
            }
            $id = DB::table('staff_territories')->insertGetId([...$path, 'staff_id' => $staff->id, 'level' => $place->level, 'place_code_id' => $place->id,
                'territory_key' => $key, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $data['level'] = $place->level;
            Audit::record($actor->id, 'staff.territory_assigned', (string) $id, ['staff_id' => $staff->id, 'place_code' => $place->code]);
            $locale = $staff->preferredLocale();
            $label = collect($path)->filter()->implode(' / ').' ('.$place->code.')';
            Audit::notice(
                $staff->id,
                __('portal.notice_staff_territory_title', [], $locale),
                __('portal.notice_staff_territory_body', [
                    'level' => $data['level'],
                    'territory' => $label,
                ], $locale),
            );
            DB::afterCommit(function () use ($staff, $data, $label): void {
                app(PartnerEventSms::class)->send(
                    $staff,
                    'staff.territory_assigned',
                    SmsText::make('sms.staff_territory_assigned', [
                        'level' => $data['level'],
                        'territory' => $label,
                    ]),
                );
            });

            return $id;
        }, 3);
    }

    public function remove(User $actor, int $territoryId, string $reason): void
    {
        abort_unless($actor->allows('staff_admin'), 403);

        DB::transaction(function () use ($actor, $territoryId, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('staff_admin'), 403);
            $row = DB::table('staff_territories')->where('id', $territoryId)->where('is_active', true)->first();
            abort_unless($row !== null, 404);
            $staff = User::findOrFail((int) $row->staff_id);
            $path = [];
            foreach (self::Fields as $field) {
                $value = trim((string) ($row->{$field} ?? ''));
                if ($value === '') {
                    break;
                }
                $path[$field] = $value;
                if ($field === $row->level) {
                    break;
                }
            }
            $label = collect($path)->filter()->implode(' / ');
            DB::table('staff_territories')->where('id', $territoryId)->update([
                'is_active' => false,
                'territory_key' => null,
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'staff.territory_removed', (string) $territoryId, [
                'staff_id' => $staff->id,
                'reason' => $reason,
            ]);
            $locale = $staff->preferredLocale();
            Audit::notice(
                $staff->id,
                __('portal.notice_staff_territory_removed_title', [], $locale),
                __('portal.notice_staff_territory_removed_body', [
                    'level' => $row->level,
                    'territory' => $label,
                    'reason' => $reason,
                ], $locale),
            );
            DB::afterCommit(function () use ($staff, $row, $label): void {
                app(PartnerEventSms::class)->send(
                    $staff,
                    'staff.territory_removed',
                    SmsText::make('sms.staff_territory_removed', [
                        'level' => $row->level,
                        'territory' => $label,
                    ]),
                );
            });
        }, 3);
    }

    /**
     * Net sales in the month inside the given territories, matched by the place code id the
     * order captured at that territory's level. Territories without a place code count nothing.
     *
     * @param  list<array<string, mixed>>|null  $regions
     */
    public function volume(int $staffId, string $month, ?array $regions = null): int
    {
        CommissionQualification::period($month);
        $regions ??= $this->territories($staffId);
        $byLevel = [];
        foreach ($regions as $region) {
            $id = filter_var($region['place_code_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $level = (string) ($region['level'] ?? '');
            if ($id !== false && in_array($level, self::Fields, true)) {
                $byLevel[$level][$id] = (string) $id;
            }
        }
        if ($byLevel === []) {
            return 0;
        }
        $query = DB::table('commission_batches')->where('period_month', $month)->where('no_commission', false);
        $query->where(function ($outer) use ($byLevel): void {
            foreach ($byLevel as $level => $ids) {
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $outer->orWhereRaw("policy->'geography'->'place_ids'->>? IN (".$placeholders.')', [$level, ...array_values($ids)]);
            }
        });

        return Money::cents((string) $query->selectRaw('COALESCE(SUM(cash_basis - refunded_amount),0) AS volume')->value('volume'));
    }
}
