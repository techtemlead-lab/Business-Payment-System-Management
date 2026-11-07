<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Office tables and forms that used to show raw codes (order.submitted, receipt,
 * info, Father name) now ship plain Bangla and English labels to the page.
 */
class OfficePlainLabelsLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['madina.financial_activation' => false]);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function sectionsWithLabels(): array
    {
        return [
            'activity log actions' => ['/audit', ['event_order_submitted' => 'অর্ডার দেওয়া হয়েছে', 'event_payment_verified' => 'পেমেন্ট যাচাই হয়েছে']],
            'stock movement types' => ['/inventory', ['stock_kind_receipt' => 'স্টক এসেছে', 'stock_kind_dispatch' => 'অর্ডারে পাঠানো']],
            'dashboard banner styles' => ['/dashboards', ['dash_mgmt_banner_style_info' => 'সাধারণ তথ্য', 'dash_mgmt_banner_style_warning' => 'সতর্কতা']],
            'account editor fields' => ['/accounts', ['acct_edit_father_name' => 'পিতার নাম', 'acct_village' => 'গ্রাম / রাস্তা', 'staff_role_sr' => 'সেলস রিপ্রেজেন্টেটিভ (SR)']],
            'employee roles' => ['/employees', ['staff_role_employee' => 'কর্মচারী']],
        ];
    }

    /**
     * @param  array<string, string>  $expected
     */
    #[DataProvider('sectionsWithLabels')]
    public function test_owner_pages_send_bangla_labels_instead_of_codes(string $path, array $expected): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($owner)->onPortal('sadmin')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($owner)->onPortal('sadmin')->get($path)
            ->assertOk()
            ->assertInertia(function ($page) use ($expected) {
                foreach ($expected as $key => $text) {
                    $page->where('translations.portal.'.$key, $text);
                }

                return $page;
            });
    }

    public function test_every_new_label_exists_in_both_languages(): void
    {
        $keys = [
            'dash_mgmt_banner_style_info', 'dash_mgmt_banner_style_success', 'dash_mgmt_banner_style_warning',
            'stock_kind_receipt', 'stock_kind_dispatch', 'stock_kind_adjustment', 'stock_kind_opening_import',
            'acct_edit_name_bn', 'acct_edit_father_name', 'acct_edit_mother_name',
        ];
        $english = require lang_path('en/portal.php');
        $bangla = require lang_path('bn/portal.php');
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $english, $key);
            $this->assertArrayHasKey($key, $bangla, $key);
            $this->assertNotSame($english[$key], $bangla[$key], $key);
        }
    }

    /**
     * Every action written to the activity log has a plain English and Bangla
     * name, so the log never falls back to words built from the code.
     */
    public function test_every_activity_log_action_has_a_label_in_both_languages(): void
    {
        $actions = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/Audit::record\(\s*(?:[^,()]|\([^()]*\))+,\s*([^,]+?)\s*,/', (string) file_get_contents($file->getPathname()), $calls);
            foreach ($calls[1] as $argument) {
                preg_match_all("/'([a-z_]+\.[a-z_.]+)'/", $argument, $codes);
                array_push($actions, ...$codes[1]);
            }
        }
        // Codes built at run time from a decision, status or kind.
        array_push($actions,
            'order.approved', 'order.held', 'order.rejected', 'order.cancelled', 'order.submitted', 'order.dispatched',
            'order.courier_booked', 'order.courier_booking_failed', 'payment.claimed', 'payment.verified', 'payment.rejected',
            'shipment.delivered', 'payroll.prepared', 'payroll.approved', 'payroll.rejected', 'payroll.paid',
            'reserve.approved', 'reserve.rejected', 'withdrawal.approved', 'withdrawal.rejected',
            'dealer_application.approved', 'dealer_application.rejected',
            'policy_schedule.approved', 'policy_schedule.rejected', 'policy_schedule.cancelled',
            'commissions.salary_pool_settled', 'commissions.royalty_pool_settled',
            'payroll.receipt_downloaded', 'reserve.receipt_downloaded', 'recovery.receipt_downloaded',
            'opening.receipt_downloaded', 'payment.receipt_downloaded',
        );
        $actions = array_unique($actions);
        $this->assertGreaterThan(150, count($actions));

        $english = require lang_path('en/portal.php');
        $bangla = require lang_path('bn/portal.php');
        foreach ($actions as $action) {
            $key = 'event_'.str_replace('.', '_', $action);
            $this->assertArrayHasKey($key, $english, $action);
            $this->assertArrayHasKey($key, $bangla, $action);
            $this->assertNotSame($english[$key], $bangla[$key], $action);
        }
    }

    public function test_every_staff_permission_has_a_name_in_both_languages(): void
    {
        $english = require lang_path('en/portal.php');
        $bangla = require lang_path('bn/portal.php');
        $permissions = array_unique(array_merge(...array_values(config('madina.roles'))));
        foreach ($permissions as $permission) {
            foreach (['perm_'.$permission, 'perm_'.$permission.'_hint'] as $key) {
                $this->assertArrayHasKey($key, $english, $key);
                $this->assertArrayHasKey($key, $bangla, $key);
                $this->assertNotSame($english[$key], $bangla[$key], $key);
            }
        }
    }

    public function test_every_page_has_its_own_subtitle_in_both_languages(): void
    {
        $english = require lang_path('en/portal.php');
        $bangla = require lang_path('bn/portal.php');
        $operations = ['data-imports', 'service-integrations', 'field-sales', 'staff-operations', 'payroll', 'my-payroll', 'payment-accounts',
            'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness', 'financial-reports', 'finance-settings',
            'access-management', 'video-marketing', 'stock', 'product-in', 'product-out', 'order'];
        $keys = array_map(fn (string $section): string => 'section_hint_'.str_replace('-', '_', $section), $operations);
        foreach (config('portals') as $portal => $definition) {
            $prefix = in_array($portal, ['manage', 'sadmin'], true) ? 'section_hint_' : 'section_hint_partner_';
            foreach ($definition['sections'] as $section) {
                // The home page greets by name; staff product and stock pages pick their own wording.
                if ($section === 'overview' || ($prefix === 'section_hint_' && in_array($section, ['catalog', 'inventory'], true))) {
                    continue;
                }
                $keys[] = $prefix.str_replace('-', '_', $section);
            }
        }
        foreach (array_unique($keys) as $key) {
            $this->assertArrayHasKey($key, $english, $key);
            $this->assertArrayHasKey($key, $bangla, $key);
            $this->assertNotSame($english['section_hint'], $english[$key], $key);
            $this->assertNotSame($english[$key], $bangla[$key], $key);
        }
    }
}
