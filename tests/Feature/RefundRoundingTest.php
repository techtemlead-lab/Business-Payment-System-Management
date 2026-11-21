<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionReversal;
use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Orders\OrderService;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Owner's rule (2026-11-21): after any refund an order's money still adds up to the paisa. The company share and the
 * Commission extra fund (worked out from the order batch, as the funds page does) plus every commission row must
 * equal what the buyer still paid; the paisa that separate rounding leaves are placed, never lost or counted twice.
 */
class RefundRoundingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 12, 10)->setTime(11, 0));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function refunds(): array
    {
        return [
            'one odd partial refund' => ['333.33', ['100.01']],
            'three partial refunds then the rest' => ['999.97', ['0.01', '333.33', '77.77', '588.86']],
            'many small refunds' => ['101.03', ['1.01', '2.02', '3.03', '0.07', '0.11']],
            'a whole refund at once' => ['250.49', ['250.49']],
        ];
    }

    /** @param list<string> $refunds */
    #[DataProvider('refunds')]
    public function test_the_order_money_adds_up_to_the_paisa_after_every_refund(string $cash, array $refunds): void
    {
        $order = $this->postedOrder($cash);
        $this->assertSame(0, $this->gap($order), 'Posting itself adds up.');

        foreach ($refunds as $index => $amount) {
            app(CommissionReversal::class)->refund($order->fresh(), Money::cents($amount), 'Returned goods', 'refund-'.$index);
            $this->assertSame(0, $this->gap($order), "After refund {$amount}");
        }
    }

    public function test_a_repeated_refund_places_nothing_twice(): void
    {
        $order = $this->postedOrder('333.33');
        app(CommissionReversal::class)->refund($order->fresh(), 10_001, 'Returned goods', 'refund-1');
        $rows = CommissionAllocation::where('order_id', $order->id)->get(['id', 'amount', 'status'])->toArray();

        app(CommissionReversal::class)->refund($order->fresh(), 10_001, 'Returned goods', 'refund-1');

        $this->assertSame($rows, CommissionAllocation::where('order_id', $order->id)->get(['id', 'amount', 'status'])->toArray());
        $this->assertSame(0, $this->gap($order));
    }

    public function test_rounding_paisa_kept_by_the_company_go_to_the_commission_extra_fund_with_their_reason(): void
    {
        $order = $this->postedOrder('999.97');
        foreach (['0.01', '333.33', '77.77'] as $index => $amount) {
            app(CommissionReversal::class)->refund($order->fresh(), Money::cents($amount), 'Returned goods', 'refund-'.$index);
        }

        foreach (CommissionAllocation::where('order_id', $order->id)->where('metadata->refund_rounding', true)->get() as $row) {
            $this->assertSame(RetainedFundLedger::BenefitsCategory, $row->category);
            $this->assertSame('split_remainder', $row->metadata['source']);
            $this->assertSame('money_core.reason_refund_rounding', $row->metadata['reason_key']);
            $this->assertNull($row->user_id);
        }
        $this->assertSame(0, $this->gap($order));
    }

    /** What the buyer still paid, less every place the order's money is counted (positive: paisa in no fund). */
    private function gap(Order $order): int
    {
        $batch = DB::selectOne(
            'SELECT cash_basis - refunded_amount AS net, ROUND(company_share * (cash_basis - refunded_amount) / NULLIF(cash_basis, 0), 2) AS company, '
            .'ROUND(unallocated * (cash_basis - refunded_amount) / NULLIF(cash_basis, 0), 2) AS benefits FROM commission_batches WHERE order_id = ?',
            [$order->id],
        );
        $rows = DB::table('commission_allocations')->where('order_id', $order->id)->where('status', '!=', 'reversed')
            ->where(fn ($query) => $query
                ->where(fn ($own) => $own->whereRaw("metadata->>'source_allocation_id' IS NULL")->where('category', '!=', RetainedFundLedger::BenefitsCategory))
                ->orWhere('metadata->refund_rounding', true))
            ->sum('amount');

        return Money::cents((string) $batch->net) - Money::cents((string) $batch->company) - Money::cents((string) $batch->benefits) - Money::cents((string) $rows);
    }

    private function postedOrder(string $cash): Order
    {
        $sponsor = User::factory()->create();
        ReferralTreePath::attachUser($sponsor->id, null);
        $buyer = User::factory()->create(['sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($buyer->id, $sponsor->id);
        $product = Product::factory()->create(['mrp' => bcmul($cash, '1.7', 2), 'dealer_price' => $cash]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());

        return $order->fresh();
    }
}
