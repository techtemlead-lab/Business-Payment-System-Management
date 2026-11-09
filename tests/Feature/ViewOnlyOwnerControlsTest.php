<?php

namespace Tests\Feature;

use App\Domain\Finance\ReportData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * MD and Chairman read the office pages but the server refuses most changes. A normal click must
 * not lead them to a refusal, and nothing here gives them any new right.
 */
class ViewOnlyOwnerControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_salary_tile_opens_for_md_and_chairman_too(): void
    {
        foreach (['md', 'chairman', 'super_admin'] as $role) {
            $this->actingAs(User::factory()->staff($role)->create())->onPortal('sadmin')->get('/treasury/work')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('tiles', fn ($tiles): bool => collect($tiles)->contains('key', 'payroll')));
        }
    }

    public function test_md_and_chairman_see_and_download_salary_but_cannot_change_it(): void
    {
        foreach (['md', 'chairman'] as $role) {
            $viewer = User::factory()->staff($role)->create();
            $entry = (string) Str::uuid();

            $this->actingAs($viewer)->onPortal('sadmin')->get('/operations/payroll')->assertOk();
            $this->assertNotNull(app(ReportData::class)->query($viewer, 'payroll', '2026-11-01', '2026-11-30')['query']);
            $this->actingAs($viewer)->onPortal('sadmin')->postJson('/commands/operations/payroll/prepare', ['period_month' => '2026-09'])->assertForbidden();
            $this->actingAs($viewer)->onPortal('sadmin')->postJson('/commands/operations/payroll/'.$entry.'/review', ['decision' => 'approved', 'reason' => 'Trying to approve it'])->assertForbidden();
            $this->actingAs($viewer)->onPortal('sadmin')->postJson('/commands/operations/payroll/'.$entry.'/pay', ['reason' => 'Trying to pay it'])->assertForbidden();
            $this->actingAs($viewer)->onPortal('sadmin')->postJson('/commands/operations/payroll/contracts', ['reason' => 'Trying to add one'])->assertForbidden();
        }
    }

    public function test_md_still_cannot_add_a_branch_or_save_the_limit(): void
    {
        $md = User::factory()->staff('md')->create();

        $this->actingAs($md)->onPortal('sadmin')->postJson('/commands/operations/accounting/approval-limit', ['amount' => '1000.00', 'version' => 0, 'reason' => 'Trying to change it'])->assertForbidden();
        $this->actingAs($md)->onPortal('sadmin')->postJson('/commands/branches', ['name' => 'New branch'])->assertForbidden();
    }

    public function test_the_withdrawal_limit_form_is_offered_only_to_owners_who_can_save_it(): void
    {
        foreach (['md' => false, 'chairman' => false, 'super_admin' => true] as $role => $canEdit) {
            $this->actingAs(User::factory()->staff($role)->create())->onPortal('sadmin')->get('/operations/finance-settings')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('approvalLimit.can_edit', $canEdit));
        }
    }

    public function test_the_application_forms_say_the_name_and_phone_go_on_the_public_list(): void
    {
        $this->assertStringContainsString("__('site.partner_directory_notice')", (string) file_get_contents(resource_path('views/reference/information.blade.php')));
        $this->assertStringContainsString('ops_application_directory_notice', (string) file_get_contents(resource_path('js/components/office/sections/FieldSales.tsx')));
        foreach (['bn', 'en'] as $locale) {
            $this->assertNotSame('site.partner_directory_notice', __('site.partner_directory_notice', [], $locale));
            $this->assertNotSame('portal.ops_application_directory_notice', __('portal.ops_application_directory_notice', [], $locale));
        }
    }

    public function test_buttons_the_server_refuses_are_hidden_by_the_same_rule(): void
    {
        $sources = [
            'pages/Portal.tsx' => "canManage={actionAllowed('/commands/branches',staffActions,'post')}",
            'components/office/sections/Policies.tsx' => "actionAllowed('/commands/policies',staffActions,'post')&&",
            'pages/Accounting.tsx' => "actionAllowed('/commands/operations/accounting/statements'",
        ];
        foreach ($sources as $file => $rule) {
            $this->assertStringContainsString($rule, (string) file_get_contents(resource_path('js/'.$file)), $file);
        }
    }
}
