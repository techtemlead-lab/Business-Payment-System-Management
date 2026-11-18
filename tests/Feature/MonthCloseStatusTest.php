<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\MonthCloseStatus;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * MA-01, MA-23 and MA-15: the Settlements page shows every month still waiting (and the older
 * ones only a person may close), when each month closed, any automatic close that failed, and the
 * owner decides whether matured commission is paid on its own.
 */
class MonthCloseStatusTest extends TestCase
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

    private function purchase(User $buyer, string $cash = '10000.00', string $mrp = '20000.00'): Order
    {
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order->fresh());

        return $order;
    }

    public function test_an_older_month_waits_for_a_person_and_the_page_offers_to_close_it(): void
    {
        $this->purchase(User::factory()->dealer()->create(['status' => 'active']));
        // September is four months back in January: too old for the hourly job.
        $this->travelTo(now()->setDate(2027, 1, 5)->setTime(10, 0));
        $owner = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/settlements')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.settlement_state.close_status.waiting', fn ($waiting): bool => collect($waiting)
                    ->contains(fn (array $row): bool => $row['month'] === '2026-09' && $row['kind'] === 'salary' && $row['automatic'] === false))
                ->where('data.settlement_state.close_status.month_options.0.value', '2027-01'));

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/commissions/settle-salary', ['period_month' => '2026-09', 'request_key' => (string) Str::uuid()])
            ->assertRedirect();

        $this->assertDatabaseMissing('commission_allocations', ['category' => 'dealer_monthly_salary_pool', 'status' => 'pending']);
        $this->assertNotNull(app(MonthCloseStatus::class)->closedAt('2026-09', 'salary'));
    }

    public function test_closing_a_month_again_says_when_it_was_closed_instead_of_fund_empty(): void
    {
        $this->purchase(User::factory()->dealer()->create(['status' => 'active']));
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(2, 0));
        $this->artisan('finance:auto-approve')->assertSuccessful();
        $owner = User::factory()->create(['role' => 'super_admin', 'locale' => 'en']);
        app()->setLocale('en');

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/commissions/settle-salary', ['period_month' => '2026-09', 'request_key' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'already closed'));
    }

    public function test_a_failed_automatic_close_is_shown_until_the_month_closes(): void
    {
        $this->purchase(User::factory()->dealer()->create(['status' => 'active']));
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(2, 0));
        // A real failure: the opening books are no longer marked verified, so every close refuses.
        $verifications = DB::table('financial_verifications')->get()->map(fn (object $row): array => (array) $row)->all();
        DB::table('financial_verifications')->delete();

        $this->artisan('finance:auto-approve')->assertFailed();

        $failures = collect(app(MonthCloseStatus::class)->failures())->keyBy('kind');
        $this->assertSame('2026-09', $failures['salary']['month']);
        $this->assertNotSame('', $failures['salary']['error']);
        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->onPortal('sadmin')->get('/settlements?period_month=2026-09')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.settlement_state.close_status.failures', fn ($rows): bool => collect($rows)->contains('kind', 'salary')));

        DB::table('financial_verifications')->insert($verifications);
        app(CommissionService::class)->settleMonthlySalaryPool('2026-09');
        $this->assertFalse(collect(app(MonthCloseStatus::class)->failures())->contains('kind', 'salary'));
    }

    public function test_the_owner_turns_automatic_matured_release_on_and_staff_cannot(): void
    {
        config(['madina.auto_release_matured' => false]);
        $settings = app(FinanceSettings::class);
        $this->assertSame(['enabled' => false, 'version' => 0, 'from_server_setting' => true], $settings->autoReleaseMatured());
        $finance = User::factory()->staff('finance')->create();
        $owner = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/settings/auto-release-matured', ['enabled' => 1, 'version' => 0, 'reason' => 'Turn it on please'])
            ->assertForbidden();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/auto-release-matured', ['enabled' => 1, 'version' => 0, 'reason' => 'Pay matured commission on time'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['enabled' => true, 'version' => 1, 'from_server_setting' => false], $settings->autoReleaseMatured());
        $this->assertDatabaseHas('audit_events', ['action' => 'finance.auto_release_matured_changed']);
    }

    public function test_the_matured_release_job_follows_the_owner_switch(): void
    {
        config(['madina.auto_release_matured' => true]);
        DB::table('commission_rule_parameters')->where('rule_key', 'commission_cooling_off')
            ->update(['is_active' => true, 'parameters' => json_encode(['cooling_off_days' => 1], JSON_THROW_ON_ERROR)]);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->purchase($dealer);
        $this->assertDatabaseHas('commission_allocations', ['user_id' => $dealer->id, 'category' => 'own_dealer_cashback', 'status' => 'maturing']);
        $this->travel(2)->days();
        $owner = User::factory()->create(['role' => 'super_admin']);
        app(FinanceSettings::class)->saveAutoReleaseMatured($owner, false, 0, 'Finance pays by hand for now');

        $this->artisan('finance:release-matured')->assertSuccessful();
        $this->assertDatabaseHas('commission_allocations', ['user_id' => $dealer->id, 'category' => 'own_dealer_cashback', 'status' => 'maturing']);

        app(FinanceSettings::class)->saveAutoReleaseMatured($owner, true, 1, 'Pay matured commission on time');
        $this->artisan('finance:release-matured')->assertSuccessful();
        $this->assertDatabaseHas('commission_allocations', ['user_id' => $dealer->id, 'category' => 'own_dealer_cashback', 'status' => 'available']);
    }
}
