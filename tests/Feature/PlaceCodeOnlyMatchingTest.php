<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Area commissions and the division salary are matched by place code only. Two places can
 * share a name ("Sadar", "Rangpur"), and a partner with no place code never matches by name.
 */
class PlaceCodeOnlyMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_two_districts_with_the_same_name_in_different_divisions_do_not_share_area_commission(): void
    {
        $sadarInRangpur = PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Rangpur']))->create(['name' => 'Sadar']);
        $sadarInKhulna = PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Khulna']))->create(['name' => 'Sadar']);
        $rangpurSeat = $this->areaDealer($sadarInRangpur);
        $khulnaSeat = $this->areaDealer($sadarInKhulna);

        $rangpurOrder = $this->postedOrder(User::factory()->inPlace($sadarInRangpur)->create());
        $khulnaOrder = $this->postedOrder(User::factory()->inPlace($sadarInKhulna)->create());

        $this->assertSame($rangpurSeat->id, $this->areaShare($rangpurOrder, 'district')->user_id);
        $this->assertSame($khulnaSeat->id, $this->areaShare($khulnaOrder, 'district')->user_id);
        $this->assertSame($sadarInRangpur->id, $this->areaShare($rangpurOrder, 'district')->metadata['place_code_id']);
    }

    public function test_a_buyer_without_a_place_code_sends_every_area_share_to_the_reserve_even_when_names_match(): void
    {
        $rangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $this->areaDealer($rangpur);
        // An old seat saved with names only, matching the buyer's names exactly.
        $legacySeat = User::factory()->create();
        AreaDealerAssignment::query()->create([
            'user_id' => $legacySeat->id, 'level' => 'division', 'zone_name' => 'Rangpur', 'division' => 'Rangpur',
            'monthly_quota' => '0', 'is_active' => true,
        ]);
        $buyer = User::factory()->create(['division' => 'Rangpur', 'district' => 'Rangpur']);

        $order = $this->postedOrder($buyer);

        $this->assertSame('unplaced', $order->fresh()->commission_policy_snapshot['geography']['resolution']);
        foreach (['division', 'district', 'upazila', 'union'] as $level) {
            $share = $this->areaShare($order, $level);
            $this->assertNull($share->user_id, $level);
            $this->assertSame('unassigned_reserve', $share->status, $level);
            $this->assertSame('company_geographic_reserve', $share->metadata['retained_by'], $level);
        }
        $this->assertSame(0, CommissionAllocation::query()->where('user_id', $legacySeat->id)->count());
    }

    public function test_a_seat_saved_without_a_place_code_never_earns_from_a_placed_buyer_with_the_same_names(): void
    {
        $rangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $legacySeat = User::factory()->create();
        AreaDealerAssignment::query()->create([
            'user_id' => $legacySeat->id, 'level' => 'division', 'zone_name' => 'Rangpur', 'division' => 'Rangpur',
            'monthly_quota' => '0', 'is_active' => true,
        ]);

        $order = $this->postedOrder(User::factory()->inPlace($rangpur)->create());

        $share = $this->areaShare($order, 'division');
        $this->assertNull($share->user_id);
        $this->assertSame('unassigned_reserve', $share->status);
    }

    public function test_two_divisions_with_the_same_name_do_not_share_the_division_salary(): void
    {
        $first = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $second = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $dealerInFirst = User::factory()->inPlace($first)->create();
        $buyerInSecond = User::factory()->inPlace($second)->create();
        $this->purchase($dealerInFirst, '10000.00', '20000.00');
        $this->purchase($buyerInSecond, '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));

        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        // Each dealer only shares the 2% salary pool of their own division's orders.
        $this->assertSame('200.00', $this->salaryPaid($dealerInFirst));
        $this->assertSame('200.00', $this->salaryPaid($buyerInSecond));
        $this->assertSame('0.00', $result['total_to_company_profit']);
        $this->assertCount(2, $result['divisions']);
    }

    public function test_dealers_and_orders_without_a_place_code_never_share_the_division_salary(): void
    {
        $unplacedDealer = User::factory()->create(['division' => 'Rangpur']);
        $unplacedBuyer = User::factory()->create(['division' => 'Rangpur']);
        $this->purchase($unplacedDealer, '10000.00', '20000.00');
        $this->purchase($unplacedBuyer, '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));

        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        $this->assertSame('0.00', $this->salaryPaid($unplacedDealer));
        $this->assertSame('0.00', $this->salaryPaid($unplacedBuyer));
        $this->assertSame('400.00', $result['total_to_company_profit']);
    }

    private function areaDealer(PlaceCode $place): User
    {
        $dealer = User::factory()->inPlace($place)->create();
        AreaDealerAssignment::query()->create([
            'user_id' => $dealer->id,
            'level' => $place->level,
            'zone_name' => $place->name,
            'place_code_id' => $place->id,
            'monthly_quota' => '0',
            'is_active' => true,
        ]);

        return $dealer;
    }

    private function postedOrder(User $buyer): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'delivered', 'paid' => $order->total]);
        app(CommissionPosting::class)->process($order);

        return $order;
    }

    private function purchase(User $buyer, string $cash, string $mrp): Order
    {
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);

        return $order;
    }

    private function areaShare(Order $order, string $level): CommissionAllocation
    {
        return CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'area_'.$level)->firstOrFail();
    }

    private function salaryPaid(User $user): string
    {
        return Money::decimal((int) DB::table('commission_settlement_payouts')
            ->where('user_id', $user->id)->where('kind', 'salary')->get()->sum(fn ($row) => Money::cents((string) $row->amount)));
    }
}
