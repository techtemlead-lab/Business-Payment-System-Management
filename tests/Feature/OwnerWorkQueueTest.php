<?php

namespace Tests\Feature;

use App\Domain\Finance\OwnerApprovalLimits;
use App\Domain\Orders\StaffDashboardSummary;
use App\Domain\Payouts\ManualPayoutService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CR-16: the owner's "waiting for you" list keeps only what waits for the owner, plus staff work
 * that no active staff member can do. The day's figures still count everything.
 */
class OwnerWorkQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFinancialPosting();
        $this->owner = User::factory()->staff('super_admin')->create(['status' => 'active']);
    }

    public function test_the_owners_list_keeps_only_owner_work_while_staff_can_do_the_rest(): void
    {
        $this->setLimit('withdrawal', '2000.00');
        // The books were opened by two finance staff in setUp; they leave, so nobody but the owner can check money.
        User::query()->where('role', 'finance')->update(['status' => 'suspended']);
        $partner = $this->partnerWithWallet('5000.00');
        $this->withdraw($partner, '500.00');

        $alone = collect(app(StaffDashboardSummary::class)->for($this->owner)['work_queue'])->keyBy('key');
        $this->assertArrayNotHasKey('staff_handles', $alone['withdrawals_to_review'], 'With no finance staff the owner still sees the work.');

        User::factory()->staff('finance')->create();
        $this->withdraw($partner, '2500.00');
        $queue = collect(app(StaffDashboardSummary::class)->for($this->owner)['work_queue'])->keyBy('key');

        $this->assertTrue($queue['withdrawals_to_review']['staff_handles']);
        $this->assertSame(2, $queue['withdrawals_to_review']['count'], 'The figures still count every request.');
        $this->assertSame(1, $queue['owner_withdrawals']['count']);
        $this->assertArrayNotHasKey('owner_refunds', $queue->all(), 'Owner rows show only while something waits.');
        $staffQueue = collect(app(StaffDashboardSummary::class)->for(User::factory()->staff('finance')->create())['work_queue'])->keyBy('key');
        $this->assertArrayNotHasKey('owner_withdrawals', $staffQueue->all());
        $this->assertArrayNotHasKey('staff_handles', $staffQueue['withdrawals_to_review']);
    }

    private function setLimit(string $kind, string $amount): void
    {
        app(OwnerApprovalLimits::class)->save($this->owner, $kind, $amount, app(OwnerApprovalLimits::class)->current($kind)['version'], 'Test limit');
    }

    private function partnerWithWallet(string $available): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'nid' => '1234567890']);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], ['available' => $available, 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);

        return $dealer;
    }

    private function withdraw(User $dealer, string $amount): string
    {
        return app(ManualPayoutService::class)->request($dealer, [
            'amount' => $amount, 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
