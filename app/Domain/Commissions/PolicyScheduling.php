<?php

namespace App\Domain\Commissions;

use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PolicyScheduling
{
    public const RecipientCategories = ['own_dealer_cashback', 'direct_referral', 'd2d_gen2', 'd2d_gen3', 'area_division', 'area_district', 'area_upazila', 'area_union'];

    public function __construct(private CommissionPolicy $policies) {}

    /** @param array<string, mixed> $data */
    public function propose(User $actor, array $data): int
    {
        abort_unless($actor->canAct('finance', 'create'), 403);

        return DB::transaction(function () use ($actor, $data): int {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->canAct('finance', 'create'), 403);
            $buyer = $data['scope'] === 'global' ? null : User::whereKey($data['user_id'])->whereIn('role', ['dealer', 'sub_dealer'])->where('status', 'active')->firstOrFail();
            $at = CarbonImmutable::parse($data['effective_at'])->utc();
            if ($at->lt(CarbonImmutable::now()->subMinute())) {
                throw ValidationException::withMessages(['effective_at' => __('portal.err_sched_backdated')]);
            }
            if ($data['scope'] === 'recipient') {
                if (empty($data['rules']) || array_diff(array_keys($data['rules']), self::RecipientCategories) !== []) {
                    throw ValidationException::withMessages(['rules' => __('portal.err_sched_recipient_categories')]);
                }
                $rules = [];
                foreach ($data['rules'] as $key => $rule) {
                    if (! is_array($rule)) {
                        throw ValidationException::withMessages(['rules' => __('portal.err_sched_rule_object')]);
                    }
                    $validated = Validator::make($rule, ['percentage' => 'required|numeric|between:0,100|decimal:0,2',
                        'funding_mode' => 'sometimes|in:company,allocations', 'funding_sources' => 'sometimes|array'])->validate();
                    $sources = $validated['funding_sources'] ?? [];
                    if (array_diff(array_keys($sources), RecipientFunding::Sources) || isset($sources[$key]) || $sources && ($validated['funding_mode'] ?? null) !== 'allocations') {
                        throw ValidationException::withMessages(['funding_sources' => __('portal.err_sched_funding_sources')]);
                    }
                    foreach ($sources as $percentage) {
                        Validator::make(['percentage' => $percentage], ['percentage' => 'required|numeric|gt:0|lte:100|decimal:0,2'])->validate();
                    }
                    $rules[$key] = $validated;
                }
            } else {
                $rules = $this->policies->compose($this->policies->snapshot(true, $buyer, $at)['rules'], $data['rules']);
            }
            $id = DB::table('commission_policy_schedules')->insertGetId(['scope' => $data['scope'], 'user_id' => $buyer?->id,
                'rules' => json_encode($rules, JSON_THROW_ON_ERROR), 'effective_at' => $at, 'expires_at' => empty($data['expires_at']) ? null : CarbonImmutable::parse($data['expires_at'])->utc(),
                'reason' => $data['reason'], 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'policy_schedule.proposed', (string) $id, ['scope' => $data['scope'], 'user_id' => $buyer?->id]);

            return $id;
        }, 3);
    }

    public function review(User $actor, int $id, string $decision, string $reason): void
    {
        abort_unless($actor->canAct('finance', 'approve'), 403);
        DB::transaction(function () use ($actor, $id, $decision, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->canAct('finance', 'approve'), 403);
            $row = DB::table('commission_policy_schedules')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($decision === 'cancelled') {
                if (! in_array($row->status, ['draft', 'approved'], true)) {
                    throw ValidationException::withMessages(['schedule' => __('portal.err_sched_closed')]);
                }
                DB::table('commission_policy_schedules')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            } else {
                if ($row->status !== 'draft' || $row->created_by === $actor->id || ($row->expires_at && CarbonImmutable::parse($row->expires_at)->isPast())) {
                    throw ValidationException::withMessages(['schedule' => __('portal.err_sched_separate_reviewer')]);
                }
                $rules = json_decode($row->rules, true, flags: JSON_THROW_ON_ERROR);
                if ($row->scope !== 'recipient') {
                    $this->policies->validateSchedule($rules);
                }
                DB::table('commission_policy_schedules')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
                if ($row->scope === 'global') {
                    DB::table('commission_policy_revisions')->insert(['rules' => $row->rules, 'source' => 'scheduled', 'schedule_id' => $id,
                        'effective_at' => $row->effective_at, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            Audit::record($actor->id, 'policy_schedule.'.$decision, (string) $id, ['reason' => $reason]);
        }, 3);
    }
}
