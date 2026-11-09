<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerCertificates;
use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerFullReport;
use App\Domain\Accounts\PartnerMoneyBook;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One dealer, in taka: ৳50 credited, ৳16 waiting to mature, ৳20 released after a hold, ৳10 reward,
 * and ৳30 credited then taken back in full. ৳15.60 is still held and was never credited.
 * My Money adds up to ৳96; every other page must say the same.
 */
class PartnerEarningsAgreeWithMyMoneyTest extends TestCase
{
    use RefreshDatabase;

    private User $dealer;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($this->dealer->id, null);
        $this->member = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $this->dealer->id]);
        ReferralTreePath::attachUser($this->member->id, $this->dealer->id);
        $this->travelTo(now('UTC')->setDate(2026, 11, 9)->setTime(8, 0));

        $this->credited($this->dealer, 'own_dealer_cashback', '50.00', 'available');
        $this->credited($this->dealer, 'area_union', '16.00', 'maturing');
        $this->credited($this->dealer, 'area_union', '15.60', 'held');
        $released = $this->credited($this->dealer, 'area_union', '20.00', 'available', ['released_at' => now()->toIso8601String()]);
        $this->entry('commission.hold_release.'.$released, $this->dealer, '20.00');
        $this->entry('reserve.award.1', $this->dealer, '10.00');

        $takenBack = $this->credited($this->dealer, 'direct_referral', '30.00', 'reversed');
        $refundId = (string) Str::uuid();
        DB::table('commission_refunds')->insert(['id' => $refundId, 'order_id' => DB::table('commission_allocations')->where('id', $takenBack)->value('order_id'), 'reference' => 'R-1', 'amount' => '30.00', 'reason' => 'Returned', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('commission_reversals')->insert(['refund_id' => $refundId, 'allocation_id' => $takenBack, 'user_id' => $this->dealer->id, 'amount' => '30.00', 'recovered' => '30.00', 'unrecovered' => '0.00', 'source_status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $this->entry('commissions.reversal.'.$refundId, $this->dealer, '-30.00');

        $this->credited($this->member, 'direct_referral', '40.00', 'available');
        $this->credited($this->member, 'area_union', '7.00', 'held');
    }

    public function test_the_book_counts_released_and_waiting_money_but_not_held_money(): void
    {
        $this->assertSame(9600, app(PartnerMoneyBook::class)->earnedTotal((int) $this->dealer->id));
        $this->assertSame('96.00', app(PartnerMoneyBook::class)->summary($this->dealer)['lifetime']['earned']);
    }

    public function test_the_home_figures_leave_out_held_money_and_match_my_money(): void
    {
        $snapshot = app(PartnerDashboardHome::class)->for($this->dealer)['snapshot'];

        $this->assertSame('96.00', $snapshot['commission_month']);
        $this->assertSame('96.00', $snapshot['commission_today']);
    }

    public function test_the_full_report_leaves_out_held_money_and_matches_my_money(): void
    {
        $report = app(PartnerFullReport::class)->for($this->dealer);

        $this->assertSame('96.00', $report['summary']['commission']);
        $this->assertSame('40.00', $report['summary']['team_commission'], 'Held money of the team is left out too.');
        $byHead = array_column($report['commissions'], 'amount', 'category');
        $this->assertSame('36.00', $byHead['area_union']);
        $this->assertArrayNotHasKey('direct_referral', $byHead, 'Money taken back in full leaves nothing to show.');
        $this->assertSame('10.00', $byHead['reward']);
    }

    public function test_the_income_certificate_leaves_out_held_money_and_matches_my_money(): void
    {
        $income = app(PartnerCertificates::class)->income($this->dealer, 2026);

        $this->assertSame('96.00', $income['total']);
        $this->assertSame(['area_union', 'own_dealer_cashback', 'reward'], array_column($income['lines'], 'category'));
        $this->assertSame('36.00', array_column($income['lines'], 'amount', 'category')['area_union']);
    }

    public function test_the_full_report_counts_delivered_goods_only(): void
    {
        $buyer = User::factory()->dealer()->create(['status' => 'active']);
        $this->order($buyer, 'approved', '2000.00', '2000.00');
        $this->order($buyer, 'delivered', '6000.00', '6000.00');
        $partly = $this->order($buyer, 'partly_delivered', '10000.00', '10000.00');
        $lineId = DB::table('order_lines')->insertGetId(['order_id' => $partly->id, 'product_id' => Product::factory()->create()->id, 'name' => 'Item', 'sku' => 'S', 'quantity' => 5, 'unit_price' => '2000.00', 'mrp' => '2000.00', 'total' => '10000.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipments')->insert(['order_id' => $partly->id, 'courier' => 'Test', 'tracking' => 'T-1', 'status' => 'delivered', 'courier_status' => 'delivered', 'actor_id' => $buyer->id,
            'lines' => json_encode([['line_id' => $lineId, 'name' => 'Item', 'quantity' => 3, 'lots' => []]], JSON_THROW_ON_ERROR),
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'created_at' => now(), 'updated_at' => now()]);

        $report = app(PartnerFullReport::class)->for($buyer);

        $this->assertSame('12000.00', $report['summary']['ordered']);
        $this->assertSame('12000.00', $report['summary']['paid']);
        $this->assertSame(3, $report['summary']['orders_count'], 'Orders stay in the count; only money waits for delivery.');
        $this->assertEqualsCanonicalizing(['0.00', '6000.00', '6000.00'], array_column($report['orders'], 'total'));
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function credited(User $partner, string $category, string $amount, string $status, array $metadata = []): int
    {
        $order = $this->order($partner, 'delivered', '1000.00', '1000.00');
        DB::table('journal_entries')->insert(['id' => (string) Str::uuid(), 'event_key' => 'commissions.order.'.$order->id, 'description' => 'Commission', 'created_at' => now()]);

        return (int) DB::table('commission_allocations')->insertGetId([
            'order_id' => $order->id, 'user_id' => $partner->id, 'category' => $category, 'rate_percentage' => '10.00', 'cash_basis' => '1000.00',
            'amount' => $status === 'reversed' ? '0.00' : $amount, 'original_amount' => $amount, 'status' => $status, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function entry(string $key, User $partner, string $change): void
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => $key, 'description' => 'Entry', 'created_at' => now()]);
        $credit = (float) $change > 0;
        DB::table('journal_lines')->insert(['entry_id' => $id, 'user_id' => $partner->id, 'account' => 'wallet_liability', 'debit' => $credit ? '0.00' : ltrim($change, '-'), 'credit' => $credit ? $change : '0.00']);
    }

    private function order(User $buyer, string $status, string $total, string $paid): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::random(8), 'user_id' => $buyer->id, 'status' => $status, 'channel' => 'dealer', 'currency' => 'BDT',
            'subtotal' => $total, 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => $total, 'paid' => $paid,
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random()),
        ]);
    }
}
