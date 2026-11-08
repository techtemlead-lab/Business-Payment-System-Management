<?php

namespace Tests\Feature;

use App\Domain\Support\ChatRouting;
use App\Domain\Support\PartnerChat;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Support\DashboardManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
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
        $this->actingAs(User::factory()->staff('inventory')->create())->onPortal('manage')->get('/withdrawals')->assertForbidden();
    }

    public function test_partners_keep_their_own_withdrawals_page(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/withdrawals')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'withdrawals'));
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

    public function test_support_role_with_no_branch_sees_no_partner_chat(): void
    {
        $branch = $this->branch('DHK-01');
        $support = $this->supportIn();
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'active_branch_id' => $branch->id]);

        $this->assertTrue(PartnerChat::canAnswer($support));
        $this->assertFalse(PartnerChat::canSeePartner($support, $dealer));
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
            foreach (['nav_module_messages', 'nav_module_partners', 'nav_module_website', 'nav_module_office', 'login_rules', 'section_hint_login_rules', 'perm_support_desk', 'perm_support_desk_hint', 'sub_dealer_applications'] as $key) {
                $this->assertNotSame('portal.'.$key, __('portal.'.$key), "$locale $key");
            }
        }
    }
}
