<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Geography\PlaceGeographyResolver;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Settling a month must not run extra queries for every active dealer: each
 * dealer's division, the sub-dealer partners and the payees are loaded together.
 */
class CommissionSettlementQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $rangpur;

    private PlaceCode $rangpurDistrict;

    private PlaceCode $dhaka;

    private PlaceCode $dhakaDistrict;

    private int $outsideDealers = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());

        $this->rangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $this->rangpurDistrict = PlaceCode::factory()->district($this->rangpur)->create();
        $this->rangpur->update(['base_place_id' => $this->rangpurDistrict->id]);
        $this->dhaka = PlaceCode::factory()->division()->create(['name' => 'Dhaka']);
        $this->dhakaDistrict = PlaceCode::factory()->district($this->dhaka)->create();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_settling_a_month_runs_the_same_number_of_queries_for_3_or_9_dealers(): void
    {
        $rangpurA = $this->assignedDealer($this->rangpurDistrict);
        $rangpurB = User::factory()->create(['division' => 'Rangpur', 'district' => 'Rangpur']);
        $sub = User::factory()->create(['role' => 'sub_dealer']);
        $this->addOutsideDealers(1);
        $this->assertSame(3, User::query()->where('role', 'dealer')->where('status', 'active')->count());
        $this->purchase($rangpurA, '10000.00', '20000.00');
        $this->purchase($rangpurB, '10000.00', '20000.00');
        $this->purchase($sub, '12000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));

        [$september, $withThreeDealers] = $this->countQueries(fn (): array => app(CommissionService::class)->settleMonthlySalaryPool('2026-09'));

        $this->travelTo(now()->setDate(2026, 10, 2));
        $this->addOutsideDealers(6);
        $this->assertSame(9, User::query()->where('role', 'dealer')->where('status', 'active')->count());
        $this->purchase($rangpurA, '10000.00', '20000.00');
        $this->purchase($rangpurB, '10000.00', '20000.00');
        $this->purchase($sub, '12000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 11, 1));

        [$october, $withNineDealers] = $this->countQueries(fn (): array => app(CommissionService::class)->settleMonthlySalaryPool('2026-10'));

        $this->assertSame('settled', $september['status']);
        $paid = fn (array $result): array => collect($result['distributed'])->pluck('amount', 'user_id')->sortKeys()->all();
        $this->assertSame([$rangpurA->id => '200.00', $rangpurB->id => '200.00', $sub->id => '360.00'], $paid($september));
        $this->assertSame($paid($september), $paid($october));
        $this->assertSame($september['total_to_company_profit'], $october['total_to_company_profit']);
        $this->assertSame($withThreeDealers, $withNineDealers, 'Settlement queries must not grow with the number of dealers.');
    }

    public function test_salary_plan_runs_the_same_number_of_queries_for_3_or_9_dealers(): void
    {
        $rangpurA = $this->assignedDealer($this->rangpurDistrict);
        $this->addOutsideDealers(2);
        $this->purchase($rangpurA, '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));

        [$smallPlan, $withThreeDealers] = $this->countQueries(fn (): array => app(CommissionSettlement::class)->salaryPlan('2026-09'));
        $this->addOutsideDealers(6);
        [$largePlan, $withNineDealers] = $this->countQueries(fn (): array => app(CommissionSettlement::class)->salaryPlan('2026-09'));

        $this->assertSame($smallPlan['dealers'], $largePlan['dealers']);
        $this->assertSame($withThreeDealers, $withNineDealers, 'The salary preview queries must not grow with the number of dealers.');
    }

    public function test_batch_geography_matches_one_by_one_geography_for_every_kind_of_dealer(): void
    {
        $inactivePlace = PlaceCode::factory()->district($this->dhaka)->create(['is_active' => false]);
        $dealers = collect([
            $this->assignedDealer($this->rangpurDistrict),
            $this->assignedDealer($this->rangpur),
            $this->assignedDealer($inactivePlace, ['profile' => ['place_code' => $this->dhakaDistrict->code]]),
            User::factory()->create(['profile' => ['place_code' => $this->rangpurDistrict->code]]),
            User::factory()->create(['division' => 'Dhaka', 'district' => 'Nowhere']),
            User::factory()->create(['division' => 'Atlantis', 'district' => 'Nowhere', 'profile' => ['upazila' => 'X', 'union' => 'Y']]),
            User::factory()->create(),
        ]);
        $resolver = app(PlaceGeographyResolver::class);

        $batch = $resolver->forBuyers(User::query()->whereIn('id', $dealers->pluck('id'))->get());

        foreach ($dealers as $dealer) {
            $this->assertSame($resolver->forBuyer($dealer->fresh()), $batch[$dealer->id], 'Dealer '.$dealer->id);
        }
        $this->assertSame('Rangpur', $batch[$dealers[0]->id]['division']);
        $this->assertSame($this->rangpurDistrict->id, $batch[$dealers[1]->id]['place_ids']['district']);
        $this->assertSame('place_code', $batch[$dealers[2]->id]['resolution']);
        $this->assertSame('name_match', $batch[$dealers[4]->id]['resolution']);
        $this->assertSame('legacy_names', $batch[$dealers[5]->id]['resolution']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assignedDealer(PlaceCode $place, array $attributes = []): User
    {
        $dealer = User::factory()->create($attributes);
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

    /** Dealers in another division who buy nothing: by area assignment, profile place code or division name. */
    private function addOutsideDealers(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            match ($this->outsideDealers++ % 3) {
                0 => User::factory()->create(['profile' => ['place_code' => $this->dhakaDistrict->code]]),
                1 => User::factory()->create(['division' => 'Dhaka', 'district' => 'Gazipur']),
                default => $this->assignedDealer($this->dhakaDistrict),
            };
        }
    }

    private function purchase(User $buyer, string $cash, string $mrp): Order
    {
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash,
            'sub_dealer_price' => $buyer->role === 'sub_dealer' ? $cash : null]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);

        return $order;
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return array{0: TResult, 1: int}
     */
    private function countQueries(callable $callback): array
    {
        $count = 0;
        $counting = true;
        DB::listen(function () use (&$count, &$counting): void {
            if ($counting) {
                $count++;
            }
        });
        $result = $callback();
        $counting = false;

        return [$result, $count];
    }
}
