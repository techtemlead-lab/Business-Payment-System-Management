<?php

namespace Tests\Feature;

use App\Domain\Finance\FraudAnomalyDetector;
use App\Domain\Finance\WithdrawalApprovalLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WithdrawalApprovalLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return ['amount' => '250000', 'version' => 0, 'reason' => 'Raising the limit after review', ...$overrides];
    }

    public function test_the_default_stays_at_one_lakh(): void
    {
        $this->assertSame('100000.00', app(WithdrawalApprovalLimit::class)->current()['amount']);

        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $detector = app(FraudAnomalyDetector::class);

        $this->assertFalse($detector->analyzeWithdrawal($dealer, 99999.99)['requires_dual_approval']);
        $this->assertTrue($detector->analyzeWithdrawal($dealer, 100000.00)['requires_dual_approval']);
    }

    public function test_the_owner_can_change_the_limit_and_the_new_limit_is_used(): void
    {
        $owner = $this->owner();
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/accounting/approval-limit', $this->payload())->assertRedirect();

        $this->assertSame('250000.00', app(WithdrawalApprovalLimit::class)->current()['amount']);
        $detector = app(FraudAnomalyDetector::class);
        $below = $detector->analyzeWithdrawal($dealer, 249999.00);
        $this->assertNotContains('LARGE_PAYOUT_THRESHOLD', $below['flags']);
        $this->assertContains('LARGE_PAYOUT_THRESHOLD', $detector->analyzeWithdrawal($dealer, 250000.00)['flags']);
    }

    public function test_a_change_is_written_to_the_audit_log(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/accounting/approval-limit', $this->payload())->assertRedirect();

        $event = DB::table('audit_events')->where('action', 'finance.withdrawal_approval_limit_changed')->first();
        $this->assertNotNull($event);
        $this->assertSame($owner->id, $event->actor_id);
        $metadata = json_decode($event->metadata, true);
        $this->assertSame('100000.00', $metadata['before']['amount']);
        $this->assertSame('250000.00', $metadata['after']['amount']);
        $this->assertSame('Raising the limit after review', $metadata['reason']);
    }

    public function test_finance_staff_can_see_the_limit_but_not_change_it(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        $this->actingAs($finance)->onPortal('manage')->get('/operations/accounting?tab=limit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvalLimit.amount', '100000.00')
                ->where('approvalLimit.can_edit', false));

        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/accounting/approval-limit', $this->payload())->assertForbidden();
        $this->assertSame('100000.00', app(WithdrawalApprovalLimit::class)->current()['amount']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'finance.withdrawal_approval_limit_changed']);
    }

    public function test_a_stale_version_is_refused(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/accounting/approval-limit', $this->payload())->assertRedirect();

        $this->actingAs($owner)->onPortal('sadmin')->postJson('/commands/operations/accounting/approval-limit', $this->payload(['amount' => '300000']))
            ->assertStatus(422);

        $this->assertSame('250000.00', app(WithdrawalApprovalLimit::class)->current()['amount']);
    }

    public function test_zero_and_nonsense_amounts_are_refused(): void
    {
        $owner = $this->owner();

        foreach (['0', '-5', 'abc'] as $amount) {
            $this->actingAs($owner)->onPortal('sadmin')->postJson('/commands/operations/accounting/approval-limit', $this->payload(['amount' => $amount]))
                ->assertStatus(422);
        }

        $this->assertSame('100000.00', app(WithdrawalApprovalLimit::class)->current()['amount']);
    }

    public function test_the_wording_does_not_promise_a_second_approver(): void
    {
        $english = require lang_path('en/accounting.php');
        $bangla = require lang_path('bn/accounting.php');

        foreach (['limit_title', 'limit_hint', 'tab_limit', 'flash_limit_saved'] as $key) {
            $this->assertDoesNotMatchRegularExpression('/second|dual|two[- ]person|another approver/i', $english[$key], $key);
        }
        $this->assertStringContainsString('does not stop a payment', $english['limit_hint']);
        $this->assertStringContainsString('extra approver', $english['limit_hint']);
        $this->assertStringContainsString('owner can finish one alone', $english['limit_hint']);
        $this->assertStringContainsString('বাড়তি কোনো অনুমোদনকারীও চায় না', $bangla['limit_hint']);
        $this->assertStringContainsString('একাই', $bangla['limit_hint']);
    }

    public function test_the_change_needs_a_reason(): void
    {
        $this->actingAs($this->owner())->onPortal('sadmin')->postJson('/commands/operations/accounting/approval-limit', $this->payload(['reason' => '']))
            ->assertStatus(422);
    }
}
