<?php

namespace Tests\Feature;

use App\Domain\Support\UserManual;
use App\Models\User;
use App\Support\DashboardManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PortalSource;
use Tests\TestCase;

/**
 * Wording, page names and small layout fixes from the signed-in portal check.
 */
class SignedInRoundWordingNavTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_website_page_links_open_on_the_public_site_address(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/website')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('publicBase', 'http://example.test')
                ->where('data.websitePages.1.slug', '/about'));

        $section = (string) file_get_contents(resource_path('js/components/website/WebsiteLayout.tsx'));
        $this->assertStringContainsString('href={`${publicBase}${slug}`}', $section);
        $this->assertStringNotContainsString('href={slug}', $section);
    }

    public function test_order_quantity_column_has_its_own_heading_and_the_money_heading_stays(): void
    {
        $this->assertSame('Quantity ordered', __('portal.col_ordered_quantity', [], 'en'));
        $this->assertSame('অর্ডারের পরিমাণ', __('portal.col_ordered_quantity', [], 'bn'));
        $this->assertSame('অর্ডার মূল্য', __('portal.report_col_ordered', [], 'bn'));

        $orderPage = (string) file_get_contents(resource_path('js/pages/OrderDetail.tsx'));
        $this->assertStringContainsString("key: 'quantity', title: translate('portal', 'col_ordered_quantity')", $orderPage);
        $this->assertStringNotContainsString('report_col_ordered', $orderPage);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function fundPageNames(): array
    {
        return [
            'English' => ['en', 'Money in and out', 'Fund balances'],
            'Bangla' => ['bn', 'টাকার আসা-যাওয়া', 'ফান্ডের ব্যালেন্স'],
        ];
    }

    #[DataProvider('fundPageNames')]
    public function test_the_two_company_money_pages_have_different_names_everywhere(string $locale, string $moneyFlowName, string $fundBalancesName): void
    {
        $this->assertSame($locale === 'bn' ? 'ট্রেজারি' : 'Treasury', __('portal.treasury', [], $locale));
        $this->assertStringContainsString((string) __('portal.treasury', [], $locale), (string) __('manual.menu_office_s6', [], $locale));
    }

    public function test_the_owner_pages_receive_the_new_names_in_bangla(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($owner)->onPortal('sadmin')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury/funds')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.portal.treasury', 'ট্রেজারি')
                ->where('translations.treasury.title', 'ট্রেজারি'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function partnerRoles(): array
    {
        return [
            'dealer' => ['dealer'],
            'sub-dealer' => ['sub_dealer'],
        ];
    }

    #[DataProvider('partnerRoles')]
    public function test_partner_help_does_not_name_the_owner_and_staff_logins(string $role): void
    {
        $partner = User::factory()->create(['role' => $role, 'status' => 'active']);
        $manual = app(UserManual::class);

        foreach (['en', 'bn'] as $locale) {
            app()->setLocale($locale);
            $wholeManual = json_encode($manual->chaptersFor($partner), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('sadmin', $wholeManual, $locale);
            $this->assertSame([], $manual->chaptersFor($partner, 'sadmin'), $locale);

            $chapter = $manual->chapter('getting_started', $partner);
            $this->assertIsArray($chapter, $locale);
            $chapterText = json_encode($chapter, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->assertDoesNotMatchRegularExpression('/\bmanage\b/i', $chapterText, $locale);
            $this->assertStringContainsString('subdealer', $chapter['steps'][0], $locale);
            $this->assertNotSame('', (string) $chapter['purpose'], $locale);
            $this->assertNotSame('', (string) $chapter['why'], $locale);
            $this->assertCount(10, $chapter['steps'], $locale);
        }
    }

    public function test_owner_and_staff_help_still_names_every_login(): void
    {
        $manual = app(UserManual::class);
        $people = [
            User::factory()->create(['role' => 'super_admin', 'status' => 'active']),
            User::factory()->staff('finance')->create(),
        ];

        foreach ($people as $person) {
            $chapter = $manual->chapter('getting_started', $person);
            $this->assertIsArray($chapter);
            $this->assertStringContainsString('sadmin', $chapter['steps'][0]);
            $this->assertStringContainsString('manage', $chapter['steps'][0]);
        }
    }

    public function test_portal_footer_reads_as_a_time_zone_not_an_address(): void
    {
        $this->assertSame('Times are shown in Bangladesh time', __('portal.footer_timezone_currency', [], 'en'));
        $this->assertSame('সময় দেখানো হয় বাংলাদেশ সময়ে', __('portal.footer_timezone_currency', [], 'bn'));
    }

    public function test_notification_hint_does_not_mention_stock(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('stock', (string) __('portal.your_updates_hint', [], 'en'));
        $this->assertStringNotContainsString('স্টক', (string) __('portal.your_updates_hint', [], 'bn'));
    }

    public function test_welcome_line_names_the_right_role_in_both_languages(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        app()->setLocale('en');
        $this->assertStringContainsString('dealer dashboard', DashboardManagement::noticeForUser($dealer)['text']);
        $this->assertStringContainsString('sub-dealer panel', DashboardManagement::noticeForUser($subDealer)['text']);
        $this->assertStringNotContainsStringIgnoringCase('branch', DashboardManagement::noticeForUser($subDealer)['text']);

        app()->setLocale('bn');
        $this->assertStringContainsString('ডিলার ড্যাশবোর্ডে', DashboardManagement::noticeForUser($dealer)['text']);
        $this->assertStringContainsString('সাব-ডিলার প্যানেলে', DashboardManagement::noticeForUser($subDealer)['text']);
        $this->assertStringNotContainsString('ব্রাঞ্চ', DashboardManagement::noticeForUser($subDealer)['text']);
    }

    public function test_welcome_line_shows_only_on_the_dashboard_and_an_owner_notice_on_every_page(): void
    {
        $shell = (string) file_get_contents(resource_path('js/components/Shell.tsx'));

        $this->assertStringContainsString("dashboardNotice.text && (dashboardNotice.every_page || section === 'overview' || section === '') && (", $shell);
        $this->assertStringContainsString('dashboardNotice?.maintenance && !staff && (', $shell);
    }

    public function test_activity_log_shows_only_the_plain_label(): void
    {
        $portal = (string) file_get_contents(resource_path('js/components/WorkLogTable.tsx'));
        $this->assertStringContainsString("auditActionLabel(String(row.action ?? ''), translate)", $portal);
        $this->assertStringNotContainsString('{row.action}</small>', $portal);

        foreach (['event_purchase_order_received', 'event_shipment_received_back'] as $key) {
            $english = trans('portal.'.$key, [], 'en');
            $bangla = trans('portal.'.$key, [], 'bn');
            $this->assertNotSame('portal.'.$key, $english);
            $this->assertNotSame('portal.'.$key, $bangla);
            $this->assertNotSame($english, $bangla);
        }
    }

    public function test_website_status_pills_stay_on_a_phone_screen(): void
    {
        $css = (string) file_get_contents(resource_path('css/screens-editor.css'));

        $this->assertStringContainsString('.ws-page .ed-row:has(>:is(.hub-pill,.ed-state-pill)){flex-wrap:wrap}', $css);
        $this->assertStringContainsString('.ws-page :is(.hub-pill,.ed-state-pill){max-width:100%;white-space:normal', $css);
    }
}
