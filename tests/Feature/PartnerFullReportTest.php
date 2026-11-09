<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerFullReport;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerFullReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_full_report_page_moves_to_my_money(): void
    {
        $dealer = User::factory()->dealer()->create();
        $sub = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/full-report')->assertRedirect('/my-money');
        $this->actingAs($sub)->onPortal('subdealer')->get('/full-report')->assertRedirect('/my-money');
    }

    public function test_dealer_can_download_full_report_pdf(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MNHC-PDF-1',
        ]);
        ReferralTreePath::attachUser($dealer->id, null);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/full-report/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename=Madina-partner-report-MNHC-PDF-1.pdf');
    }

    public function test_bangla_dealer_full_report_pdf_uses_partner_locale_strings(): void
    {
        $this->assertSame('পার্টনার সম্পূর্ণ রিপোর্ট', __('portal.pdf_partner_full_report_title', [], 'bn'));
        $this->assertSame('Partner full report', __('portal.pdf_partner_full_report_title', [], 'en'));

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MNHC-PDF-BN',
            'locale' => 'bn',
        ]);
        ReferralTreePath::attachUser($dealer->id, null);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/full-report/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_staff_cannot_download_partner_full_report_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')
            ->get('/full-report/pdf')
            ->assertNotFound();
    }

    public function test_pdf_status_words_are_plain_in_both_languages(): void
    {
        $this->assertSame('আংশিক পাঠানো', PartnerFullReport::statusWord('partially_dispatched', 'bn'));
        $this->assertSame('সাব-ডিলার', PartnerFullReport::roleWord('sub_dealer', 'bn'));
        $this->assertStringNotContainsString('_', PartnerFullReport::statusWord('partially_dispatched', 'en'));
        $this->assertStringNotContainsString('_', PartnerFullReport::roleWord('sub_dealer', 'en'));
    }
}
