<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Finance\MonthCloseStatus;
use App\Domain\Orders\OrderService;
use App\Models\CommissionAllocation;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner's decisions (2026-11-21): every ended month with open fund money is closed and shown while it waits, the
 * sub-dealer extra kept in the old rank fund included; a failed close is kept in the database, so clearing the cache
 * cannot hide it.
 */
class MonthCloseCompletenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 15)->setTime(11, 0));
    }

    public function test_sub_dealer_extra_kept_in_the_rank_fund_waits_on_the_list_until_its_month_is_closed(): void
    {
        app(CommissionPolicy::class)->update(User::factory()->create(['role' => 'super_admin']), ['additional_rank' => ['parameters' => [CommissionPolicy::ExtraDestinationParameter => 'rank_fund']]]);
        $sub = User::factory()->create(['role' => 'sub_dealer']);
        $product = Product::factory()->create(['mrp' => '15000.00', 'dealer_price' => '10000.00', 'sub_dealer_price' => '10000.00']);
        $order = app(OrderService::class)->create($sub, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => '10000.00', 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());
        $this->assertSame(1, CommissionAllocation::where('category', 'additional_rank_pool')->where('status', 'pending')->count());

        $this->travelTo(now()->setDate(2026, 12, 2)->setTime(1, 0));
        $waiting = collect(app(MonthCloseStatus::class)->waitingMonths())->where('kind', 'additional_rank');
        $this->assertSame(['2026-09'], $waiting->pluck('month')->values()->all(), 'An old month is listed, not only the last two.');
        $this->assertTrue($waiting->first()['automatic']);

        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertSame([], collect(app(MonthCloseStatus::class)->waitingMonths())->where('kind', 'additional_rank')->values()->all());
        $this->assertSame(0, CommissionAllocation::where('category', 'additional_rank_pool')->where('status', 'pending')->count());
        $this->assertContains('additional_rank', array_column(app(MonthCloseStatus::class)->closedMonths(), 'kind'));
    }

    public function test_a_failed_close_survives_a_cache_clear_and_goes_when_the_month_closes(): void
    {
        $status = app(MonthCloseStatus::class);
        $status->recordFailure('salary', '2026-08', 'Database went away');
        $status->recordFailure('salary', '2026-08', 'Database went away again');
        Cache::flush();

        $failures = $status->failures();
        $this->assertCount(1, $failures, 'One line per kind and month.');
        $this->assertSame(['salary', '2026-08', 'Database went away again'], [$failures[0]['kind'], $failures[0]['month'], $failures[0]['error']]);

        $status->clearFailure('salary', '2026-08');

        $this->assertSame([], $status->failures());
    }
}
