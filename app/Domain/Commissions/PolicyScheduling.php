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
                $changes = array_map(fn (array $rule): array => ['before' => [], 'after' => $rule], $rules);
            } else {
                $base = $this->policies->snapshot(true, $buyer, $at)['rules'];
                $rules = $this->policies->compose($base, $data['rules']);
                $changes = PolicyChangeSummary::diff(array_intersect_key($base, $data['rules']), array_intersect_key($rules, $data['rules']));
            }
            $id = DB::table('commission_policy_schedules')->insertGetId(['scope' => $data['scope'], 'user_id' => $buyer?->id,
                'rules' => json_encode($rules, JSON_THROW_ON_ERROR), 'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                'effective_at' => $at, 'expires_at' => empty($data['expires_at']) ? null : CarbonImmutable::parse($data['expires_at'])->utc(),
                'reason' => $data['reason'], 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'policy_schedule.proposed', (string) $id, ['scope' => $data['scope'], 'user_id' => $buyer?->id, 'changes' => $changes]);

            return $id;
        }, 3);
    }

    /**
     * Approve, reject or cancel a commission change.
     *
     * Approving or rejecting is for the owner (Super Admin) only, and never for the person who
     * proposed it. A pending change can be withdrawn by its proposer or a finance approver; an
     * approved one changes live rates when cancelled, so only the owner may cancel it.
     */
    public function review(User $actor, int $id, string $decision, string $reason): void
    {
        abort_unless($actor->canAct('finance', 'approve') || CommissionPolicy::approves($actor), 403);
        DB::transaction(function () use ($actor, $id, $decision, $reason): void {
            CommissionWallet::lock();
            $actor = $actor->fresh();
            $row = DB::table('commission_policy_schedules')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $changes = is_string($row->changes ?? null) ? json_decode($row->changes, true, flags: JSON_THROW_ON_ERROR) : null;
            $proposerId = (int) $row->created_by;
            if ($decision === 'cancelled') {
                if ($row->status === 'approved') {
                    abort_unless(CommissionPolicy::approves($actor), 403, __('portal.err_policy_owner_only'));
                    if ($row->origin === 'rule_edit') {
                        // Already written into the live rules: undo it with a new change instead.
                        throw ValidationException::withMessages(['schedule' => __('portal.err_policy_change_already_live')]);
                    }
                } elseif ($row->status === 'draft') {
                    abort_unless($proposerId === (int) $actor->id || $actor->canAct('finance', 'approve') || CommissionPolicy::approves($actor), 403);
                } else {
                    throw ValidationException::withMessages(['schedule' => __('portal.err_sched_closed')]);
                }
                DB::table('commission_policy_schedules')->where('id', $id)->update(['status' => 'cancelled', 'reviewed_by' => $actor->id,
                    'reviewed_at' => now(), 'review_reason' => $reason, 'updated_at' => now()]);
            } else {
                abort_unless(CommissionPolicy::approves($actor), 403, __('portal.err_policy_owner_only'));
                if ($row->status !== 'draft' || $proposerId === (int) $actor->id || ($row->expires_at && CarbonImmutable::parse($row->expires_at)->isPast())) {
                    throw ValidationException::withMessages(['schedule' => __('portal.err_sched_separate_reviewer')]);
                }
                if ($decision === 'rejected') {
                    DB::table('commission_policy_schedules')->where('id', $id)->update(['status' => 'rejected', 'reviewed_by' => $actor->id,
                        'reviewed_at' => now(), 'review_reason' => $reason, 'updated_at' => now()]);
                } else {
                    $this->approve($actor, $row);
                    DB::table('commission_policy_schedules')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(),
                        'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $reason, 'updated_at' => now()]);
                }
            }
            Audit::record($actor->id, 'policy_schedule.'.$decision, (string) $id, [
                'reason' => $reason,
                'scope' => $row->scope,
                'origin' => $row->origin,
                'proposed_by' => $proposerId,
                'reviewed_by' => $actor->id,
                'changes' => $changes,
            ]);
        }, 3);
    }

    private function approve(User $actor, object $row): void
    {
        $rules = json_decode($row->rules, true, flags: JSON_THROW_ON_ERROR);
        if ($row->scope !== 'recipient') {
            $this->policies->validateSchedule($rules);
        }
        if ($row->origin === 'rule_edit') {
            // The change was made against the rules live at that moment. If they have moved on
            // since, approving would silently undo the newer change.
            if ((int) ($row->base_revision_id ?? 0) !== (int) ($this->policies->snapshot(false)['revision_id'] ?? 0)) {
                throw ValidationException::withMessages(['schedule' => __('portal.err_policy_change_stale')]);
            }
            $changes = json_decode((string) $row->changes, true, flags: JSON_THROW_ON_ERROR);
            $this->policies->apply($actor, $rules, array_keys($changes), (int) $row->id);

            return;
        }
        if ($row->scope === 'global') {
            DB::table('commission_policy_revisions')->insert(['rules' => $row->rules, 'source' => 'scheduled', 'schedule_id' => $row->id,
                'effective_at' => $row->effective_at, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
