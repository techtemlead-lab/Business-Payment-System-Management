<?php

namespace Tests\Feature;

use App\Domain\Finance\ReportData;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Support\OfficeHubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The commission pages work as one set of tabs and the reports as one Report Centre.
 * Every old address must still open, and only for the people it opened for before.
 */
class CommissionReportHubTest extends TestCase
{
    use RefreshDatabase;

    private const AllCommissionTabs = ['/commissions', '/policies', '/operations/policy-schedules', '/simulator'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.financial_activation' => false]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function commissionPages(): array
    {
        return [
            'split' => ['/commissions', 'commissions'],
            'written rules' => ['/policies', 'policies'],
            'special rate' => ['/operations/policy-schedules', 'policy-schedules'],
            'calculator' => ['/simulator', 'simulator'],
        ];
    }

    /**
     * Each person's permissions, and whether they open the commission pages.
     *
     * @return array<string, array{list<string>, bool}>
     */
    public static function commissionPeople(): array
    {
        return [
            'finance' => [['finance'], true],
            'orders only' => [['orders'], false],
            'reports only' => [['reports'], false],
            'accounts only' => [['accounts'], false],
            'inventory only' => [['inventory'], false],
            'field sales only' => [['field_sales'], false],
        ];
    }

    #[DataProvider('commissionPages')]
    public function test_every_old_commission_address_opens_the_same_four_tabs(string $path, string $section): void
    {
        $response = $this->office($this->staffWith(['finance']))->get($path)->assertOk();

        $hub = $this->hubOf($response);
        $this->assertSame('commissions', $hub['home']);
        $this->assertSame(self::AllCommissionTabs, array_column($hub['tabs'], 'href'));
        $this->assertSame([$section], array_column(array_filter($hub['tabs'], fn (array $tab): bool => $tab['active']), 'key'));
    }

    #[DataProvider('commissionPages')]
    public function test_the_owner_gets_the_same_tabs_on_the_owner_portal(string $path, string $section): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->assertCount(4, $this->hubOf($this->actingAs($owner)->onPortal('sadmin')->get($path)->assertOk())['tabs']);
    }

    public function test_tab_names_are_the_ones_the_owner_asked_for(): void
    {
        app()->setLocale('bn');
        $tabs = OfficeHubs::commission($this->staffWith(['finance']), true, 'commissions')['tabs'];

        $this->assertSame(['কমিশনের ভাগ ঠিক করুন', 'কমিশনের লিখিত নিয়ম', 'একজনের বিশেষ রেট', 'কমিশন ক্যালকুলেটর'], array_column($tabs, 'label'));
    }

    /**
     * @param  list<string>  $permissions
     */
    #[DataProvider('commissionPeople')]
    public function test_tabs_offered_match_what_each_person_can_really_open(array $permissions, bool $opens): void
    {
        $person = $this->staffWith($permissions);
        $offered = array_column(OfficeHubs::commission($person, true, 'commissions')['tabs'], 'href');

        $this->assertSame($opens ? self::AllCommissionTabs : [], $offered);
        foreach (self::AllCommissionTabs as $path) {
            $response = $this->office($person)->get($path);
            $opens ? $response->assertOk() : $response->assertForbidden();
        }
    }

    public function test_partners_cannot_reach_the_commission_tabs(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        foreach (self::AllCommissionTabs as $path) {
            $this->actingAs($dealer)->onPortal('dealer')->get($path)->assertNotFound();
        }
    }

    public function test_the_tab_for_an_office_only_page_is_not_offered_on_a_partner_host(): void
    {
        $tabs = OfficeHubs::commission($this->staffWith(['finance']), false, 'commissions')['tabs'];

        $this->assertSame(['/commissions', '/policies', '/simulator'], array_column($tabs, 'href'));
    }

    public function test_there_is_only_one_commission_calculator(): void
    {
        $studio = (string) file_get_contents(resource_path('js/components/CommissionStudio.tsx'));

        $this->assertStringNotContainsString('setSimOrderAmount', $studio);
        $this->assertStringNotContainsString('cs_tab_simulator', $studio);
        $this->office($this->staffWith(['finance']))->get('/simulator')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'simulator')->has('data.simulation'));
    }

    /**
     * Permissions, and where the old order-table address sends that person.
     *
     * @return array<string, array{list<string>, string}>
     */
    public static function oldReportAddressPeople(): array
    {
        return [
            'finance goes to the centre' => [['finance'], '/operations/financial-reports?group=orders'],
            'reports goes to the centre' => [['reports'], '/operations/financial-reports?group=orders'],
            'orders and reports goes to the centre' => [['orders', 'reports'], '/operations/financial-reports?group=orders'],
            'orders only goes to the order list' => [['orders'], '/orders'],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    #[DataProvider('oldReportAddressPeople')]
    public function test_the_old_order_table_address_opens_a_page_this_person_can_use(array $permissions, string $destination): void
    {
        $person = $this->staffWith($permissions);

        $this->office($person)->get('/reports')->assertRedirect($destination);
        $this->office($person)->get($destination)->assertOk();
    }

    public function test_the_old_order_table_address_keeps_its_filters_on_the_order_list(): void
    {
        $this->office($this->staffWith(['finance']))->get('/reports?status=delivered&period=month')
            ->assertRedirect('/orders?status=delivered&period=month');
        $this->office($this->staffWith(['orders']))->get('/reports?q=ABC')->assertRedirect('/orders?q=ABC');
    }

    public function test_filters_do_not_send_someone_to_an_order_list_they_cannot_open(): void
    {
        $this->office($this->staffWith(['reports']))->get('/reports?status=delivered')
            ->assertRedirect('/operations/financial-reports?group=orders');
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function peopleWithoutTheOldOrderTable(): array
    {
        return [
            'field sales' => [['field_sales']],
            'accounts' => [['accounts']],
            'inventory' => [['inventory']],
            'content' => [['content']],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    #[DataProvider('peopleWithoutTheOldOrderTable')]
    public function test_the_old_order_table_address_stays_closed_to_people_it_was_closed_to(array $permissions): void
    {
        $this->office($this->staffWith($permissions))->get('/reports')->assertForbidden();
    }

    public function test_company_wide_staff_get_every_report_once_in_its_group(): void
    {
        $this->office($this->staffWith(['finance']))->get('/operations/financial-reports')->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->where('reportGroups', function ($groups): bool {
                    $groups = collect($groups)->mapWithKeys(fn ($group): array => [$group['key'] => $group['reports']])->all();

                    $this->assertSame([
                        'orders' => ['orders', 'district-summary'],
                        'money' => ['withdrawals', 'ledger', 'refunds', 'order-refunds', 'collections', 'tds-register', 'fund-spending'],
                        'partners' => ['commissions', 'settlements', 'rewards', 'rank-achievements', 'rank-incentives'],
                        'stock' => ['products', 'product-summary', 'category-summary'],
                        'staff' => ['payroll'],
                    ], $groups);
                    $this->assertEqualsCanonicalizing(ReportData::Types, array_merge(...array_values($groups)));

                    return true;
                });
            });
    }

    public function test_branch_staff_get_only_the_order_based_files_they_had_before(): void
    {
        $branch = CompanyBranch::factory()->create();
        $person = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['orders', 'reports']], 'active_branch_id' => $branch->id]);

        $this->office($person)->get('/operations/financial-reports')->assertOk()
            ->assertInertia(function (Assert $page) use ($person): void {
                $available = app(ReportData::class)->availableTo($person);
                $page->where('reportTypes', $available);
                $page->where('reportGroups', function ($groups) use ($available): bool {
                    $listed = collect($groups)->pluck('reports')->flatten()->all();
                    $this->assertEqualsCanonicalizing($available, $listed);
                    $this->assertNotContains('commissions', $listed);
                    $this->assertNotContains('ledger', $listed);
                    $this->assertNotContains('payroll', $listed);
                    $this->assertSame(['orders', 'money', 'stock'], collect($groups)->pluck('key')->all());

                    return true;
                });
            });

        $this->office($person)->get('/commands/operations/reports?type=commissions&format=xlsx&from=2026-01-01&to=2026-01-31')->assertForbidden();
    }

    public function test_field_sales_staff_see_only_their_two_files_in_the_centre(): void
    {
        $this->office($this->staffWith(['field_sales', 'sr_orders']))->get('/operations/financial-reports')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reportTypes', ['orders', 'payroll'])
                ->where('reportGroups', fn ($groups): bool => collect($groups)->pluck('key')->all() === ['orders', 'staff']));
    }

    public function test_the_report_centre_stays_closed_to_people_it_was_closed_to(): void
    {
        $this->office($this->staffWith(['orders']))->get('/operations/financial-reports')->assertForbidden();
        $this->office($this->staffWith(['inventory']))->get('/operations/financial-reports')->assertForbidden();
    }

    public function test_group_links_only_appear_for_people_who_can_open_them(): void
    {
        $hrefs = fn (array $permissions): array => collect(OfficeHubs::reportGroups($this->staffWith($permissions), ReportData::Types))
            ->pluck('links')->flatten(1)->pluck('href')->all();

        $this->assertSame(['/orders'], $hrefs(['finance']));
        $this->assertSame([], $hrefs(['reports']));
        $this->assertSame(['/inventory'], $hrefs(['reports', 'inventory']));
    }

    public function test_a_report_added_later_is_not_lost(): void
    {
        $groups = OfficeHubs::reportGroups($this->staffWith(['finance']), ['some-new-report']);

        $this->assertSame([['money', ['some-new-report']]], array_map(fn (array $group): array => [$group['key'], $group['reports']], $groups));
    }

    public function test_the_treasury_tab_no_longer_shares_the_account_book_name(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $labels = require lang_path($locale.'/portal.php');
            $this->assertNotSame($labels['ledger'], $labels['tr_tab_ledger'], $locale);
        }
    }

    public function test_the_spending_tab_links_to_the_fund_page_and_everyone_on_the_tab_can_open_it(): void
    {
        $this->assertStringContainsString('/company-funds', (string) file_get_contents(resource_path('js/components/CompanyTreasuryHub.tsx')));

        $person = $this->staffWith(['finance']);
        $this->office($person)->get('/treasury')->assertOk();
        $this->office($person)->get('/company-funds')->assertOk();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffWith(array $permissions): User
    {
        return User::factory()->staff('employee')->create(['profile' => ['permissions' => $permissions]]);
    }

    private function office(User $person): static
    {
        return $this->actingAs($person)->onPortal('manage');
    }

    /**
     * @return array{home: string, label: string, tabs: list<array{key: string, label: string, href: string, active: bool}>}
     */
    private function hubOf(TestResponse $response): array
    {
        $props = $response->viewData('page')['props'];

        return $props['hub'] ?? $props['data']['hub'];
    }
}
