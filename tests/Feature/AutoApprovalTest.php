<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Orders\OrderService;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        config(['madina.financial_activation' => true, 'madina.preview_mode' => false]);
        $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
    }

    public function test_every_payout_is_automatic_by_default_except_car_and_bike(): void
    {
        $settings = app(FinanceSettings::class);

        $this->assertTrue($settings->autoApproval()['enabled']);
        foreach (['salary', 'royalty', 'rank_incentive'] as $item) {
            $this->assertTrue($settings->isAutoApproved($item), $item);
        }
        $this->assertFalse($settings->isAutoApproved('car_bike'));
    }

    public function test_turning_it_off_keeps_the_items_the_owner_left_automatic(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/auto-approval', [
            'enabled' => false,
            'items' => ['salary' => true, 'royalty' => false, 'rank_incentive' => false],
            'version' => 0,
            'reason' => 'We now have two finance staff to check payouts',
        ])->assertSessionHasNoErrors();

        $settings = app(FinanceSettings::class);
        $this->assertFalse($settings->autoApproval()['enabled']);
        $this->assertTrue($settings->isAutoApproved('salary'));
        $this->assertFalse($settings->isAutoApproved('royalty'));
        $this->assertFalse($settings->isAutoApproved('rank_incentive'));
    }

    public function test_dealers_cannot_change_the_setting(): void
    {
        $status = $this->actingAs(User::factory()->dealer()->create())->onPortal('dealer')->post('/commands/operations/settings/auto-approval', [
            'enabled' => false, 'items' => [], 'version' => 0, 'reason' => 'Trying to change payouts',
        ])->getStatusCode();

        // The dealer portal does not even expose company operations (404); either way nothing changes.
        $this->assertContains($status, [403, 404]);
        $this->assertTrue(app(FinanceSettings::class)->autoApproval()['enabled']);
    }

    public function test_the_scheduled_run_settles_a_closed_month_salary_on_its_own(): void
    {
        $dealer = $this->rangpurDealer();
        $this->purchase($dealer, '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(1, 0));

        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertDatabaseHas('commission_settlement_payouts', ['user_id' => $dealer->id, 'kind' => 'salary', 'period_month' => '2026-09', 'amount' => '200.00']);
        $this->assertDatabaseMissing('commission_allocations', ['category' => 'dealer_monthly_salary_pool', 'status' => 'pending']);
    }

    public function test_the_scheduled_run_leaves_months_older_than_two_for_a_person(): void
    {
        $dealer = $this->rangpurDealer();
        $this->purchase($dealer, '10000.00', '20000.00');
        // September's salary is still unpaid in January: too old for the automatic run.
        $this->travelTo(now()->setDate(2027, 1, 5)->setTime(1, 0));

        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertDatabaseCount('commission_settlement_payouts', 0);
        $this->assertDatabaseHas('commission_allocations', ['category' => 'dealer_monthly_salary_pool', 'status' => 'pending']);
    }

    public function test_the_scheduled_run_leaves_salary_alone_when_it_needs_approval(): void
    {
        $owner = User::factory()->staff()->create();
        app(FinanceSettings::class)->saveAutoApproval($owner, false, ['salary' => false, 'royalty' => true, 'rank_incentive' => true], 0, 'Salary needs a finance check');
        $this->purchase($this->rangpurDealer(), '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(1, 0));

        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertDatabaseCount('commission_settlement_payouts', 0);
        $this->assertDatabaseHas('commission_allocations', ['category' => 'dealer_monthly_salary_pool', 'status' => 'pending']);
    }

    public function test_the_scheduled_run_does_not_settle_the_month_that_is_still_open(): void
    {
        $this->purchase($this->rangpurDealer(), '10000.00', '20000.00');

        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertDatabaseCount('commission_settlement_payouts', 0);
    }

    public function test_the_scheduled_run_skips_a_rank_reward_that_is_not_earned_yet(): void
    {
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()->subDays(60)]);

        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertDatabaseCount('rank_incentive_awards', 0);
    }

    public function test_one_scheduled_run_pays_every_rank_reached_at_once(): void
    {
        $leader = $this->rangpurDealer();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'am', 'achieved_at' => now(), 'qualification_history' => [
            ['rank' => 'mo', 'achieved_at' => now()->subMinutes(30)->toIso8601String()],
            ['rank' => 'am', 'achieved_at' => now()->toIso8601String()],
        ]]);
        $this->purchase($leader, '3000000.00', '6000000.00');

        $this->artisan('finance:auto-approve')->assertSuccessful();
        $this->assertSame(['am', 'mo'], DB::table('rank_incentive_awards')->where('user_id', $leader->id)->orderBy('rank')->pluck('rank')->all());
    }

    public function test_nothing_moves_while_money_movement_is_switched_off(): void
    {
        config(['madina.financial_activation' => false]);
        $this->purchase($this->rangpurDealer(), '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(1, 0));

        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertDatabaseCount('commission_settlement_payouts', 0);
    }

    public function test_a_failed_payout_makes_the_scheduled_run_fail_loudly(): void
    {
        $this->purchase($this->rangpurDealer(), '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(1, 0));
        // Any database error during the payout; the trigger is rolled back with the test.
        DB::unprepared("CREATE FUNCTION test_reject_payout() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Ledger is locked by a long report.'; END; \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER test_reject_payout BEFORE INSERT ON commission_settlement_payouts FOR EACH ROW EXECUTE FUNCTION test_reject_payout();");
        Log::spy();

        $this->artisan('finance:auto-approve')->assertFailed();

        Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Automatic payout approval failed')
            && str_contains((string) ($context['failed']['salary:2026-09'] ?? ''), 'Ledger is locked'));
    }

    /** A dealer keyed to the Rangpur division place code (salary is shared by place code). */
    private function rangpurDealer(): User
    {
        $rangpur = PlaceCode::query()->where('level', PlaceCode::LevelDivision)->where('name', 'Rangpur')->first()
            ?? PlaceCode::factory()->division()->create(['name' => 'Rangpur']);

        return User::factory()->inPlace($rangpur)->create();
    }

    private function purchase(User $buyer, string $cash, string $mrp): Order
    {
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);

        return $order;
    }
}
