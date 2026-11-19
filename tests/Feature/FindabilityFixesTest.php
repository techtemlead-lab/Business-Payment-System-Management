<?php

namespace Tests\Feature;

use App\Domain\Accounts\CustomerBook;
use App\Domain\Content\PublicTrustContent;
use App\Models\PartnerCustomer;
use App\Models\User;
use App\Support\TranslationBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Findability fixes: plainer names, the report files tab, the home page buttons and cards, the public footer and the search words. */
class FindabilityFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_renamed_screens_use_the_plain_names_in_both_languages(): void
    {
        $names = [
            'bn' => [
                ['portal.nav_module_applications', 'নতুন পার্টনারের আবেদন'],
                ['portal.nav_module_network', 'আমার টিম ও গ্রাহক'],
                ['portal.settlements', 'মাস শেষ: কমিশন ও বেতন দিন'],
                ['treasury.tab_work', 'খরচ, বাজেট ও বাকি কাজ'],
                ['office_menu.partner_tab_security', 'পাসওয়ার্ড ও নিরাপত্তা'],
                ['office_menu.tab_financial_reports', 'রিপোর্ট ফাইল (এক্সেল)'],
                ['productivity.search_label', 'অর্ডার/পার্টনার খুঁজুন'],
                ['cr_partner.open_wallet', 'ওয়ালেট ও টাকা তোলা'],
                ['public_trust.footer_batch_check', 'পণ্য যাচাই'],
                ['public_trust.footer_articles', 'স্বাস্থ্য লেখা'],
            ],
            'en' => [
                ['portal.nav_module_applications', 'New partner applications'],
                ['portal.nav_module_network', 'My team & customers'],
                ['treasury.tab_work', 'Spending, budgets & pending work'],
                ['office_menu.partner_tab_security', 'Password & security'],
                ['office_menu.tab_financial_reports', 'Report files (Excel)'],
                ['cr_partner.open_wallet', 'Wallet & withdrawals'],
            ],
        ];
        foreach ($names as $locale => $pairs) {
            app()->setLocale($locale);
            foreach ($pairs as [$key, $expected]) {
                $this->assertSame($expected, __($key), "$locale $key");
            }
        }
    }

    public function test_the_search_words_are_in_the_translation_bundle_and_match_in_both_languages(): void
    {
        $this->assertSame('findability', TranslationBundle::Groups['findability']);
        $bn = Lang::get('findability', [], 'bn');
        $en = Lang::get('findability', [], 'en');

        $this->assertSame(array_keys($bn), array_keys($en));
        foreach ($bn as $key => $value) {
            $this->assertNotSame('', trim($value), $key);
            if (str_starts_with($key, 'kw_')) {
                // A word list is shared by both languages: Bangla and English words together.
                $this->assertSame($value, $en[$key], $key);
            }
        }
    }

    public function test_the_footer_links_the_product_check_beside_the_dealer_check(): void
    {
        $urls = array_column(PublicTrustContent::footerLinks(), 'url');

        $this->assertContains('/batch-check', $urls);
        $this->assertSame('/batch-check', $urls[array_search('/verify-dealer', $urls, true) + 1] ?? null);
        $this->get('/batch-check')->assertOk();
    }

    public function test_the_dealer_home_counts_the_customers_to_call_today_or_earlier(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $today = CustomerBook::today();
        PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'Due today', 'follow_up_on' => $today]);
        PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'Overdue', 'follow_up_on' => now()->subDays(3)->toDateString()]);
        PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'Later', 'follow_up_on' => now()->addDays(5)->toDateString()]);
        PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'No date']);
        PartnerCustomer::query()->create(['partner_id' => $other->id, 'name' => 'Someone else\'s', 'follow_up_on' => $today]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.partner_home.follow_up_due', 2));
        $this->actingAs($dealer)->onPortal('dealer')->get('/customers?status=due')->assertOk();
    }

    public function test_the_office_home_tells_staff_with_a_salary_record_to_show_the_salary_card(): void
    {
        $withSalary = User::factory()->staff('sr')->create();
        $without = User::factory()->staff('sr')->create();
        $owner = User::factory()->staff()->create();
        foreach ([$withSalary, $owner] as $person) {
            DB::table('staff_contracts')->insert(['staff_id' => $person->id, 'effective_month' => '2026-11', 'base_salary' => '20000.00', 'reason' => 'Test contract', 'created_by' => $person->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($withSalary)->onPortal('manage')->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.admin_home.has_salary_record', true));
        $this->actingAs($without)->onPortal('manage')->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.admin_home.has_salary_record', false));
        // The owners read payroll on their own pages, so no card for them even with a contract.
        $this->actingAs($owner)->onPortal('sadmin')->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.admin_home.has_salary_record', false));
    }

    public function test_the_report_files_list_gives_each_person_only_their_own_files(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $sales = User::factory()->staff('sr')->create();

        $this->actingAs($finance)->onPortal('manage')->get('/operations/financial-reports')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'financial-reports')->where('reportTypes', fn ($types): bool => count($types) > 2 && in_array('collections', (array) $types->toArray(), true)));
        $this->actingAs($sales)->onPortal('manage')->get('/operations/financial-reports')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reportTypes', ['orders', 'payroll']));
        $this->actingAs(User::factory()->staff('inventory')->create())->onPortal('manage')->get('/operations/financial-reports')->assertForbidden();
    }

    public function test_best_sellers_and_contests_open_inside_the_team_row_and_the_contract_inside_the_account_row(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $team = $this->actingAs($dealer)->onPortal('dealer')->get('/top-sellers')->assertOk()->viewData('page')['props']['data']['partner_hub'] ?? null;
        $tabs = $team ? array_column($team['tabs'], 'key') : [];
        $this->assertContains('contests', $tabs);
        $this->assertContains('top-sellers', $tabs);

        $account = $this->actingAs($dealer)->onPortal('dealer')->get('/agreement')->assertOk()->viewData('page')['props']['data']['partner_hub'] ?? null;
        $this->assertContains('agreement', $account ? array_column($account['tabs'], 'key') : []);
    }
}
