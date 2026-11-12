<?php

namespace Tests\Feature;

use App\Domain\Support\ChatRouting;
use App\Domain\Support\PartnerChat;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\OfficeHubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClassConstant;
use Tests\TestCase;

class SimpleSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function branch(string $code): CompanyBranch
    {
        return CompanyBranch::query()->create([
            'code' => $code,
            'name' => 'Branch '.$code,
            'status' => 'active',
        ]);
    }

    private function supportIn(?CompanyBranch $branch = null): User
    {
        $support = User::factory()->staff('support')->create();
        if ($branch !== null) {
            BranchStaffAssignment::query()->create(['branch_id' => $branch->id, 'user_id' => $support->id]);
        }

        return $support;
    }

    public function test_office_withdrawals_page_moves_to_the_payout_desk_with_its_filters(): void
    {
        $owner = User::factory()->staff()->create();

        $response = $this->actingAs($owner)->onPortal('sadmin')->get('/withdrawals?status=requested&q=WD-1');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/payouts-hub', $location);
        $this->assertStringContainsString('status=requested', $location);
        $this->assertStringContainsString('q=WD-1', $location);
    }

    public function test_staff_without_finance_cannot_reach_the_payout_desk_through_the_old_address(): void
    {
        $inventory = User::factory()->staff('inventory')->create();
        $this->actingAs($inventory)->onPortal('manage')->get('/withdrawals')->assertRedirect('/');
        $this->actingAs($inventory)->onPortal('manage')->get('/payouts-hub')->assertForbidden();
    }

    public function test_partners_withdraw_from_my_money(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/withdrawals')->assertRedirect('/my-money');
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('MyMoney')->where('section', 'my-money'));
    }

    public function test_partners_can_open_the_share_feedback_page(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/share-feedback')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'share-feedback'));
    }

    public function test_support_role_opens_support_requests_enquiries_and_chat(): void
    {
        $support = $this->supportIn($this->branch('DHK-01'));

        foreach (['/support-admin' => 'support-admin', '/enquiries' => 'enquiries', '/partner-messages' => 'partner-messages'] as $path => $section) {
            $this->actingAs($support)->onPortal('manage')->get($path)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('section', $section));
        }
    }

    public function test_support_role_answers_a_support_request_and_updates_an_enquiry(): void
    {
        $support = $this->supportIn();
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $ticketId = (string) Str::uuid();
        DB::table('support_tickets')->insert([
            'id' => $ticketId,
            'user_id' => $dealer->id,
            'category' => 'other',
            'subject' => 'Need help',
            'body' => 'Please help',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enquiryId = DB::table('enquiries')->insertGetId([
            'name' => 'Visitor',
            'phone' => '01711111111',
            'topic' => 'Product enquiry',
            'message' => 'Price please',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($support)->onPortal('manage')
            ->post("/commands/support-tickets/{$ticketId}/reply", ['body' => 'We are checking.', 'status' => 'in_progress'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($support)->onPortal('manage')
            ->post("/commands/enquiries/{$enquiryId}", ['status' => 'contacted'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('in_progress', DB::table('support_tickets')->where('id', $ticketId)->value('status'));
        $this->assertSame('contacted', DB::table('enquiries')->where('id', $enquiryId)->value('status'));
    }

    public function test_other_staff_still_cannot_open_the_support_desk(): void
    {
        $inventory = User::factory()->staff('inventory')->create();

        $this->actingAs($inventory)->onPortal('manage')->get('/support-admin')->assertForbidden();
        $this->actingAs($inventory)->onPortal('manage')->get('/enquiries')->assertForbidden();
        $this->actingAs($inventory)->onPortal('manage')->get('/partner-messages')->assertForbidden();
    }

    public function test_support_role_answers_chat_without_a_nomination_but_only_for_its_branch(): void
    {
        $dhaka = $this->branch('DHK-01');
        $rajshahi = $this->branch('RAJ-01');
        $support = $this->supportIn($dhaka);
        $inDhaka = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'active_branch_id' => $dhaka->id]);
        $inRajshahi = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'active_branch_id' => $rajshahi->id]);
        $routing = app(ChatRouting::class);

        $this->assertNull($routing->scopeOf($support));
        $this->assertTrue(PartnerChat::canAnswer($support));
        $this->assertTrue(PartnerChat::canSeePartner($support, $inDhaka));
        $this->assertFalse(PartnerChat::canSeePartner($support, $inRajshahi));
    }

    public function test_support_role_with_no_branch_sees_every_partner_chat(): void
    {
        $branch = $this->branch('DHK-01');
        $support = $this->supportIn();
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'active_branch_id' => $branch->id]);

        $this->assertTrue(PartnerChat::canAnswer($support));
        $this->assertTrue(PartnerChat::canSeePartner($support, $dealer));
    }

    public function test_suspended_support_staff_cannot_answer_chat(): void
    {
        $support = User::factory()->staff('support')->create(['status' => 'suspended']);

        $this->assertFalse(PartnerChat::canAnswer($support));
    }

    public function test_login_rules_are_on_their_own_owner_page(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/login-rules')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'login-rules')
                ->has('securityControls.partner_login_otp')
                ->has('securityControls.password_reset_notice'));
    }

    public function test_office_staff_cannot_open_the_login_rules(): void
    {
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get('/login-rules')->assertNotFound();
    }

    public function test_dead_dashboard_switches_are_gone_and_live_ones_stay(): void
    {
        $defaults = DashboardManagement::defaults();

        foreach (['company_treasury_enabled', 'company_commissions_enabled', 'company_customization_enabled', 'company_audit_enabled', 'employee_default_permissions'] as $dead) {
            $this->assertArrayNotHasKey($dead, $defaults);
        }
        foreach (['dealer_rewards_enabled', 'dealer_claims_enabled', 'birthday_sms_enabled', 'support_overdue_hours'] as $live) {
            $this->assertArrayHasKey($live, $defaults);
        }
    }

    public function test_every_menu_section_has_a_label_in_both_languages(): void
    {
        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (['nav_module_messages', 'nav_module_partners', 'nav_module_website', 'nav_module_office', 'nav_module_applications', 'nav_module_for_partners', 'label_web', 'login_rules', 'section_hint_login_rules', 'section_hint_settings', 'perm_support_desk', 'perm_support_desk_hint', 'sub_dealer_applications'] as $key) {
                $this->assertNotSame('portal.'.$key, __('portal.'.$key), "$locale $key");
            }
        }
    }

    public function test_every_menu_word_exists_in_both_languages_and_differs(): void
    {
        $english = require lang_path('en/office_menu.php');
        $bangla = require lang_path('bn/office_menu.php');

        $this->assertSame(array_keys($english), array_keys($bangla));
        foreach ($english as $key => $word) {
            $this->assertNotSame($word, $bangla[$key], $key);
        }
    }

    public function test_the_menu_and_the_server_agree_on_every_rows_tabs(): void
    {
        preg_match_all("/\\{ id: '(office-[a-z-]+)',.*?tabs: \\[(.*?)\\]/s", (string) file_get_contents(resource_path('js/shared/portalNav.ts')), $rows, PREG_SET_ORDER);
        $hubs = (new ReflectionClassConstant(OfficeHubs::class, 'Hubs'))->getValue();
        $this->assertNotEmpty($rows);

        foreach ($rows as [, $rowId, $tabList]) {
            preg_match_all("/'([a-z-]+)'/", $tabList, $tabs);
            $sections = array_map(fn (string $tab): string => ['catalog-products' => 'catalog', 'stock' => 'inventory'][$tab] ?? $tab, $tabs[1]);
            if ($rowId === 'office-settings') {
                $groups = (new ReflectionClassConstant(OfficeHubs::class, 'SettingsGroups'))->getValue();
                $this->assertSame(array_keys(array_merge(...array_values($groups))), $sections);

                continue;
            }
            $this->assertSame(array_keys($hubs[$rowId]['tabs']), $sections, $rowId);
        }
    }

    public function test_settings_page_offers_only_what_each_person_can_open(): void
    {
        $expected = [
            ['super_admin', 'sadmin', ['finance-settings', 'payment-accounts', 'financial-readiness', 'place-codes', 'dashboards', 'identity-review', 'login-rules', 'reason-fields', 'service-integrations', 'courier-areas', 'data-imports', 'audit', 'privacy-requests', 'data-cleanup']],
            ['finance', 'manage', ['finance-settings', 'payment-accounts', 'financial-readiness', 'identity-review', 'service-integrations', 'data-imports']],
            ['account_manager', 'manage', ['place-codes', 'dashboards', 'data-imports']],
        ];

        foreach ($expected as [$role, $portal, $links]) {
            $person = User::factory()->staff($role)->create();
            $response = $this->actingAs($person)->onPortal($portal)->get('/settings')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('section', 'settings'));
            $groups = $response->viewData('page')['props']['data']['settings_groups'];

            $this->assertSame($links, collect($groups)->pluck('links')->flatten(1)->pluck('key')->all(), $role);
            $this->assertNotContains([], collect($groups)->pluck('links')->all(), 'An empty group must not show.');
            foreach (collect($groups)->pluck('links')->flatten(1)->pluck('href') as $href) {
                $this->actingAs($person)->onPortal($portal)->get($href)->assertOk();
            }
        }
    }

    public function test_partners_have_no_settings_page(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/settings')->assertRedirect('/');
    }

    public function test_people_with_nothing_in_settings_go_to_their_home_and_keep_their_own_pages(): void
    {
        foreach (['inventory', 'support', 'sr'] as $role) {
            $person = User::factory()->staff($role)->create();
            $this->assertFalse(OfficeHubs::hasSettingsToOpen($person, 'manage', true), $role);
            $this->actingAs($person)->onPortal('manage')->get('/settings')->assertRedirect('/');
            $this->actingAs($person)->onPortal('manage')->get('/')->assertOk();
            foreach (['/profile', '/security'] as $ownPage) {
                $this->actingAs($person)->onPortal('manage')->get($ownPage)->assertOk();
            }
            $this->actingAs($person)->onPortal('manage')->get('/audit')->assertForbidden();
        }
    }

    public function test_people_with_something_in_settings_keep_the_page(): void
    {
        foreach ([['account_manager', 'manage'], ['finance', 'manage'], ['super_admin', 'sadmin']] as [$role, $portal]) {
            $person = User::factory()->staff($role)->create();
            $this->actingAs($person)->onPortal($portal)->get('/settings')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('section', 'settings'));
        }
    }

    public function test_a_page_in_a_row_gets_the_rows_tabs_in_order_with_its_own_tab_marked(): void
    {
        $inventory = User::factory()->staff('inventory')->create();

        $hub = $this->actingAs($inventory)->onPortal('manage')->get('/stock-lots')->assertOk()->viewData('page')['props']['data']['hub'];

        $this->assertSame('office-stock', $hub['home']);
        $this->assertSame('Products & stock', $hub['label']);
        $this->assertSame(['/catalog', '/inventory', '/stock-lots', '/purchasing', '/stock-counts', '/branches'], array_column($hub['tabs'], 'href'));
        $this->assertSame(['stock-lots'], array_column(array_filter($hub['tabs'], fn (array $tab): bool => $tab['active']), 'key'));
    }

    public function test_a_row_with_one_open_tab_takes_that_tabs_own_name(): void
    {
        $finance = User::factory()->staff('finance')->create();

        $hub = $this->actingAs($finance)->onPortal('manage')->get('/catalog')->assertOk()->viewData('page')['props']['data']['hub'];

        $this->assertSame('catalog', $hub['home']);
        $this->assertSame('Products', $hub['label']);
        $this->assertSame(['/catalog'], array_column($hub['tabs'], 'href'));
    }

    public function test_every_tab_offered_opens_for_that_person_and_no_other_tab_does(): void
    {
        $people = [
            ['account_manager', 'manage', '/accounts'],
            ['inventory', 'manage', '/inventory'],
            ['support', 'manage', '/support-admin'],
            ['editor', 'manage', '/website'],
            ['finance', 'manage', '/top-sellers'],
        ];

        foreach ($people as [$role, $portal, $path]) {
            $person = User::factory()->staff($role)->create();
            $hub = $this->actingAs($person)->onPortal($portal)->get($path)->assertOk()->viewData('page')['props']['data']['hub'];
            $offered = array_column($hub['tabs'], 'href');
            foreach ($offered as $href) {
                $this->actingAs($person)->onPortal($portal)->get($href)->assertOk();
            }

            $all = (new ReflectionClassConstant(OfficeHubs::class, 'Hubs'))->getValue()[$hub['home']]['tabs'] ?? [];
            foreach (array_diff($all, $offered) as $href) {
                $status = $this->actingAs($person)->onPortal($portal)->get($href)->getStatusCode();
                $this->assertContains($status, [301, 403, 404], "$role $href");
            }
        }
    }

    public function test_operations_pages_get_the_rows_tabs_and_keep_their_own_check(): void
    {
        $owner = User::factory()->staff()->create();
        $hub = $this->actingAs($owner)->onPortal('sadmin')->get('/operations/payroll')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Operations')->where('hub.home', 'office-staff'))
            ->viewData('page')['props']['hub'];

        $this->assertSame(['/employees', '/operations/payroll', '/operations/staff-operations', '/operations/field-sales', '/operations/access-management'], array_column($hub['tabs'], 'href'));
        $this->actingAs(User::factory()->staff('inventory')->create())->onPortal('manage')->get('/operations/payroll')->assertForbidden();
    }

    public function test_the_report_files_tab_is_only_for_those_who_cannot_open_the_money_report(): void
    {
        $sr = User::factory()->staff('sr')->create();
        $this->actingAs($sr)->onPortal('manage')->get('/operations/financial-reports')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hub.home', 'financial-reports')->where('hub.tabs.0.href', '/operations/financial-reports')->count('hub.tabs', 1));

        $finance = User::factory()->staff('finance')->create();
        $tabs = $this->actingAs($finance)->onPortal('manage')->get('/top-sellers')->assertOk()->viewData('page')['props']['data']['hub']['tabs'];
        $this->assertNotContains('/operations/financial-reports', array_column($tabs, 'href'));
    }

    public function test_partner_rows_get_their_tabs(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $expected = [
            '/orders' => ['/orders', '/deliveries'],
            '/network' => ['/network', '/customers', '/ranks', '/top-sellers', '/contests'],
            '/training' => ['/training', '/events', '/sales-kit'],
            '/messages' => ['/messages', '/circulars', '/share-feedback', '/help'],
            '/profile' => ['/profile', '/security', '/agreement'],
        ];

        foreach ($expected as $path => $tabs) {
            $this->actingAs($dealer)->onPortal('dealer')->get($path)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('data.partner_hub.active', ltrim($path, '/'))
                    ->where('data.partner_hub.tabs', fn ($offered) => collect($offered)->pluck('path')->all() === $tabs));
        }
    }

    public function test_sub_dealers_have_no_team_tab_but_keep_the_page(): void
    {
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        $this->actingAs($subDealer)->onPortal('subdealer')->get('/customers')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.partner_hub.tabs', fn ($offered) => collect($offered)->pluck('path')->all() === ['/customers', '/top-sellers', '/contests']));
        $this->actingAs($subDealer)->onPortal('subdealer')->get('/network')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'network')->missing('data.partner_hub'));
    }

    public function test_an_office_switch_removes_the_partners_tab(): void
    {
        DashboardManagement::save(['dealer_ranks_enabled' => false]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/network')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.partner_hub.tabs', fn ($offered) => ! collect($offered)->pluck('path')->contains('/ranks')));
    }

    public function test_a_row_left_with_one_tab_shows_no_strip_to_a_partner(): void
    {
        DashboardManagement::save(['subdealer_deliveries_enabled' => false]);
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        $this->actingAs($subDealer)->onPortal('subdealer')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'orders')->missing('data.partner_hub'));
    }
}
