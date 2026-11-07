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
}
