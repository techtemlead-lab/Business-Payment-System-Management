<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner decision 2026-11-05: commission rate changes wait for the owner's approval. */
class CommissionChangeApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->finance = User::factory()->staff('finance')->create();
    }

    private function directReferral(): string
    {
        return (string) app(CommissionPolicy::class)->snapshot()['rules']['direct_referral']['percentage'];
    }

    private function proposeDirectReferral(User $actor, string $percentage): int
    {
        $this->actingAs($actor)->onPortal('manage')
            ->post('/commands/commissions/rules', ['rules' => ['direct_referral' => ['percentage' => $percentage]], 'reason' => 'Campaign for new dealers'])
            ->assertRedirect()
            ->assertSessionHas('success', __('portal.flash_commission_change_pending'));

        return (int) DB::table('commission_policy_schedules')->where('origin', 'rule_edit')->orderByDesc('id')->value('id');
    }

    private function review(User $actor, int $id, string $decision, string $reason = 'Checked against the budget')
    {
        return $this->actingAs($actor)->postJson('/api/v1/operations/policy-schedules/'.$id.'/review', ['decision' => $decision, 'reason' => $reason]);
    }

    public function test_a_staff_change_stays_pending_and_the_old_rate_stays_in_force(): void
    {
        $before = $this->directReferral();
        $id = $this->proposeDirectReferral($this->finance, '8.00');

        $this->assertSame($before, $this->directReferral());
        $row = DB::table('commission_policy_schedules')->find($id);
        $this->assertSame('draft', $row->status);
        $this->assertSame($this->finance->id, (int) $row->created_by);
        $changes = json_decode($row->changes, true);
        $this->assertSame($before, (string) $changes['direct_referral']['before']['percentage']);
        $this->assertSame('8.00', (string) $changes['direct_referral']['after']['percentage']);

        $proposed = DB::table('audit_events')->where('action', 'commissions.rules_change_proposed')->first();
        $this->assertSame($this->finance->id, (int) $proposed->actor_id);
        $this->assertSame('8.00', (string) json_decode($proposed->metadata, true)['changes']['direct_referral']['after']['percentage']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->owner->id, 'title' => __('portal.notice_policy_change_pending_title')]);
    }

    public function test_the_owner_approves_and_the_audit_log_names_proposer_and_approver_with_old_and_new_values(): void
    {
        $before = $this->directReferral();
        $id = $this->proposeDirectReferral($this->finance, '8.00');

        $this->review($this->owner, $id, 'approved')->assertOk();

        $this->assertSame('8.00', $this->directReferral());
        $row = DB::table('commission_policy_schedules')->find($id);
        $this->assertSame('approved', $row->status);
        $this->assertSame($this->owner->id, (int) $row->approved_by);
        $event = DB::table('audit_events')->where('action', 'policy_schedule.approved')->where('subject', (string) $id)->first();
        $meta = json_decode($event->metadata, true);
        $this->assertSame($this->owner->id, (int) $event->actor_id);
        $this->assertSame($this->finance->id, $meta['proposed_by']);
        $this->assertSame($this->owner->id, $meta['reviewed_by']);
        $this->assertSame($before, (string) $meta['changes']['direct_referral']['before']['percentage']);
        $this->assertSame('8.00', (string) $meta['changes']['direct_referral']['after']['percentage']);

        // Live now, so it can no longer be cancelled away; a new change is needed.
        $this->review($this->owner, $id, 'cancelled')->assertUnprocessable();
    }

    public function test_only_the_owner_may_approve_and_nobody_approves_their_own_change(): void
    {
        $id = $this->proposeDirectReferral($this->finance, '8.00');
        $otherFinance = User::factory()->staff('finance')->create();

        $this->review($this->finance, $id, 'approved')->assertForbidden();
        $this->review($otherFinance, $id, 'approved')->assertForbidden();
        $this->review($otherFinance, $id, 'rejected')->assertForbidden();
        $this->assertSame('draft', DB::table('commission_policy_schedules')->where('id', $id)->value('status'));

        // A change the owner proposed as a schedule still cannot be approved by that same owner.
        $ownScheduleId = $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules', [
            'scope' => 'global', 'rules' => ['own_dealer_cashback' => ['percentage' => '4.00']],
            'effective_at' => now()->addHour()->toIso8601String(), 'reason' => 'Owner campaign plan',
        ])->assertOk()->json('data.id');
        $this->review($this->owner, (int) $ownScheduleId, 'approved')->assertUnprocessable();
    }

    public function test_the_owner_rejects_with_a_reason_and_nothing_changes(): void
    {
        $before = $this->directReferral();
        $id = $this->proposeDirectReferral($this->finance, '8.00');

        $this->review($this->owner, $id, 'rejected', 'Too costly this quarter')->assertOk();

        $this->assertSame($before, $this->directReferral());
        $row = DB::table('commission_policy_schedules')->find($id);
        $this->assertSame('rejected', $row->status);
        $this->assertSame('Too costly this quarter', $row->review_reason);
        $this->assertSame($this->owner->id, (int) $row->reviewed_by);
        $this->assertDatabaseHas('audit_events', ['action' => 'policy_schedule.rejected', 'actor_id' => $this->owner->id, 'subject' => (string) $id]);
        $this->review($this->owner, $id, 'approved')->assertUnprocessable();
    }

    public function test_a_change_made_against_rates_that_have_since_moved_cannot_be_approved(): void
    {
        $first = $this->proposeDirectReferral($this->finance, '8.00');
        $second = $this->proposeDirectReferral($this->finance, '7.00');

        $this->review($this->owner, $first, 'approved')->assertOk();
        $this->review($this->owner, $second, 'approved')->assertUnprocessable();
        $this->assertSame('8.00', $this->directReferral());
    }

    public function test_the_owners_own_change_applies_at_once_and_is_audited_with_old_and_new_values(): void
    {
        $before = $this->directReferral();
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/commissions/rules', ['rules' => ['direct_referral' => ['percentage' => '9.00']]])
            ->assertSessionHas('success', __('portal.flash_commission_policy_updated'));

        $this->assertSame('9.00', $this->directReferral());
        $this->assertDatabaseMissing('commission_policy_schedules', ['origin' => 'rule_edit']);
        $meta = json_decode(DB::table('audit_events')->where('action', 'commissions.rules_updated')->value('metadata'), true);
        $this->assertSame($before, (string) $meta['changes']['direct_referral']['before']['percentage']);
        $this->assertSame('9.00', (string) $meta['changes']['direct_referral']['after']['percentage']);
        $this->assertSame($this->owner->id, $meta['approved_by']);
    }

    public function test_the_api_accepts_a_staff_change_as_pending_and_returns_the_old_rates(): void
    {
        $before = $this->directReferral();
        $this->actingAs($this->finance)->patchJson('/api/v1/finance/policy', ['rules' => ['direct_referral' => ['percentage' => '8.00']]])
            ->assertStatus(202)
            ->assertJsonPath('meta.status', 'pending')
            ->assertJsonPath('data.rules.direct_referral.percentage', $before);
    }

    public function test_the_owner_sees_pending_changes_with_old_and_new_values(): void
    {
        $before = $this->directReferral();
        $this->proposeDirectReferral($this->finance, '8.00');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->getJson('/operations/policy-schedules')
            ->assertOk()
            ->assertJsonPath('data.tables.0.key', 'policy_schedules')
            ->assertJsonPath('data.tables.0.page.data.0.status', 'draft')
            ->assertJsonPath('data.tables.0.page.data.0.proposed_by_name', $this->finance->name)
            ->assertJsonPath('data.tables.0.page.data.0.changes', __('portal.rule_name_direct_referral').': '.$before.'% → 8.00%');
    }
}
