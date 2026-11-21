<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Orders\OrderService;
use App\Models\DealerRank;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PQ-01: everyday money moves share the money lock and lock only the partners they touch, rule
 * changes still take it alone, and a month close works in short chunks that add up to one close.
 */
class MoneyLockTest extends TestCase
{
    use RefreshDatabase;

    private const MoneyLockKey = 81416001;

    private const PartnerLockSpace = 81416010;

    /** A second database session, standing in for another request running at the same time. */
    private function otherSession(): ConnectionInterface
    {
        config(['database.connections.pgsql_other' => config('database.connections.pgsql')]);

        return DB::connection('pgsql_other');
    }

    private function otherCan(string $sql, array $bindings): bool
    {
        return (bool) $this->otherSession()->scalar($sql, $bindings);
    }

    public function test_shared_holders_run_side_by_side_and_wait_for_the_exclusive_lock(): void
    {
        CommissionWallet::lockShared();

        $this->assertTrue($this->otherCan('SELECT pg_try_advisory_xact_lock_shared(?)', [self::MoneyLockKey]), 'another everyday move may run');
        $this->assertFalse($this->otherCan('SELECT pg_try_advisory_xact_lock(?)', [self::MoneyLockKey]), 'a rule change waits');
    }

    public function test_the_exclusive_lock_stops_everyday_moves(): void
    {
        CommissionWallet::lock();

        $this->assertFalse($this->otherCan('SELECT pg_try_advisory_xact_lock_shared(?)', [self::MoneyLockKey]));
        // Re-entering in the same transaction never deadlocks on itself.
        CommissionWallet::lockShared();
        CommissionWallet::lockPartners(7);
        $this->assertTrue(true);
    }

    public function test_a_partner_lock_blocks_only_that_partner(): void
    {
        CommissionWallet::lockPartners(9, 5, 9);

        $this->assertFalse($this->otherCan('SELECT pg_try_advisory_xact_lock(?, ?)', [self::PartnerLockSpace, 5]));
        $this->assertFalse($this->otherCan('SELECT pg_try_advisory_xact_lock(?, ?)', [self::PartnerLockSpace, 9]));
        $this->assertTrue($this->otherCan('SELECT pg_try_advisory_xact_lock(?, ?)', [self::PartnerLockSpace, 6]));
    }

    public function test_a_month_close_in_several_chunks_pays_the_same_as_one_close_and_tells_each_partner_once(): void
    {
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
        config(['madina.settlement_close_chunk' => 2]);
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        // Five buyers of ৳10,000 and the MO himself, who buys ৳30,000 so he meets the dealer's monthly target for the royalty.
        foreach ([...array_fill(0, 5, null), $leader] as $buyer) {
            $buyer ??= User::factory()->create();
            $cash = $buyer->is($leader) ? '30000.00' : '10000.00';
            $product = Product::factory()->create(['mrp' => bcmul($cash, '2', 2), 'dealer_price' => $cash]);
            $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
            $order->update(['paid' => $cash, 'status' => 'delivered']);
            app(CommissionService::class)->processOrder($order);
        }
        $this->travelTo(now()->setDate(2026, 10, 1));

        $result = app(CommissionService::class)->settleRankRoyaltyPool('2026-09');

        // 4% of the ৳80,000 bought puts ৳3,200 in the royalty pool; the MO quarter (৳800) goes to the one MO, the rest to company profit.
        $this->assertSame('3200.00', $result['total_pool']);
        $this->assertSame('800.00', $result['total_distributed']);
        $this->assertSame('2400.00', $result['total_to_company_profit']);
        $this->assertSame('0.00', $result['total_to_commission_extra_fund']);
        // His ৳1,500 cashback plus the ৳800 royalty.
        $this->assertSame('2300.00', DB::table('wallets')->where('user_id', $leader->id)->value('available'));
        $this->assertSame(6, DB::table('commission_settlement_payouts')->where('user_id', $leader->id)->count());
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $leader->id)->where('title', __('portal.notice_settle_title'))->count());
        $this->assertSame('empty_pool', app(CommissionService::class)->settleRankRoyaltyPool('2026-09')['status']);
    }
}
