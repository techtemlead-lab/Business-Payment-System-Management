<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The old money pages are gone. Every old address must land somewhere this person can open.
 */
class OldMoneyAddressesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array{string, string}> */
    public static function officeAddresses(): array
    {
        return [
            'treasury' => ['/treasury', '/treasury/funds'],
            'capital flow' => ['/capital-flow', '/treasury/funds'],
            'company funds' => ['/company-funds', '/treasury/funds'],
            'one company fund' => ['/company-funds?fund=marketing', '/treasury/funds/marketing'],
            'spend approvals' => ['/company-funds?tab=approvals', '/treasury/work?tab=approvals'],
            'spending heads' => ['/company-funds?tab=heads', '/treasury/work?tab=heads'],
            'ledger with filters' => ['/ledger?q=MN-1&period=today', '/treasury/events?q=MN-1&period=today'],
            'car and bike rewards' => ['/rewards', '/treasury/funds/car_bike'],
            'salary' => ['/salary', '/treasury/funds/salary_pool'],
            'report centre' => ['/operations/financial-reports', '/treasury/orders'],
            'office withdrawals' => ['/withdrawals?status=approved', '/payouts-hub?status=approved'],
            'accounting statements' => ['/operations/accounting?tab=statements', '/treasury/work'],
            'accounting limit' => ['/operations/accounting?tab=limit', '/operations/finance-settings'],
            'accounting payout file' => ['/operations/accounting?tab=payouts', '/payouts-hub'],
        ];
    }

    #[DataProvider('officeAddresses')]
    public function test_finance_staff_move_to_the_new_page(string $old, string $new): void
    {
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get($old)
            ->assertStatus(301)->assertRedirect($new);
    }

    /** @return array<string, array{string, string}> */
    public static function partnerAddresses(): array
    {
        return [
            'earnings' => ['/earnings', '/my-money'],
            'full report' => ['/full-report', '/my-money'],
            'statement' => ['/statement', '/my-money'],
            'receipts' => ['/payment-receipts', '/my-money'],
            'withdrawals' => ['/withdrawals', '/my-money'],
            'office treasury address' => ['/treasury', '/my-money'],
        ];
    }

    #[DataProvider('partnerAddresses')]
    public function test_partners_move_to_my_money(string $old, string $new): void
    {
        $this->actingAs(User::factory()->dealer()->create())->onPortal('dealer')->get($old)
            ->assertStatus(301)->assertRedirect($new);
    }

    public function test_a_sub_dealers_salary_page_moves_to_their_salary_lines(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']))->onPortal('subdealer')->get('/salary')
            ->assertStatus(301)->assertRedirect('/my-money?type=salary');
    }

    public function test_the_owners_fund_address_stays_with_the_owner(): void
    {
        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/benefits-fund')
            ->assertRedirect('/treasury/funds/benefits');
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get('/benefits-fund')
            ->assertRedirect('/treasury/funds');
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get('/company-funds?fund=benefits')
            ->assertRedirect('/treasury/funds');
    }

    public function test_someone_who_may_open_only_the_order_report_is_sent_there(): void
    {
        $viewer = $this->staffWith(['reports']);

        foreach (['/treasury', '/ledger', '/rewards', '/company-funds'] as $old) {
            $this->actingAs($viewer)->onPortal('manage')->get($old)->assertRedirect('/treasury/orders');
        }
        $this->actingAs($viewer)->onPortal('manage')->get('/operations/financial-reports')->assertRedirect('/treasury/orders');
    }

    public function test_field_staff_keep_their_report_files_and_never_meet_a_403(): void
    {
        $sr = $this->staffWith(['field_sales']);

        $this->actingAs($sr)->onPortal('manage')->get('/operations/financial-reports')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Operations')->where('section', 'financial-reports')->has('reportTypes'));
        $this->actingAs($sr)->onPortal('manage')->get('/reports')->assertRedirect('/operations/financial-reports');
    }

    public function test_staff_with_no_money_rights_go_home(): void
    {
        $this->actingAs($this->staffWith(['content']))->onPortal('manage')->get('/treasury')->assertRedirect('/');
    }

    public function test_an_old_statement_link_opens_the_partners_money_page(): void
    {
        $partner = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('manage')->get("/accounts/{$partner->id}/statement")
            ->assertStatus(301)->assertRedirect("/treasury/partners/{$partner->id}");
        $this->actingAs($this->staffWith(['accounts']))->onPortal('manage')->get("/treasury/partners/{$partner->id}")->assertOk();
    }

    public function test_the_statement_files_are_still_there(): void
    {
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($dealer)->onPortal('dealer')->get('/statement/xlsx')->assertOk();
    }

    /** @param list<string> $permissions */
    private function staffWith(array $permissions): User
    {
        return User::factory()->create(['role' => 'employee', 'status' => 'active', 'profile' => ['permissions' => $permissions]]);
    }
}
