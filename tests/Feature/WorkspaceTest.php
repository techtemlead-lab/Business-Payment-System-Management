<?php

namespace Tests\Feature;

use App\Models\BusinessConfig;
use App\Models\Dealer;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\RankDefinition;
use App\Models\RuleVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function payload(): array
    {
        return ['name' => 'Review Partner', 'email' => 'partner@example.test', 'password' => 'Secure-Partner-2026!', 'code' => 'NX-TEST-001', 'level' => 'District', 'division' => 'Dhaka', 'district' => 'Dhaka'];
    }

    public function test_unauthenticated_workspace_redirects_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_creates_pending_dealer_and_an_audit_record(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/dealers', [...$this->payload(), 'role' => 'admin', 'status' => 'active', 'balance_minor' => 999999])->assertRedirect('/dealers');
        $this->assertDatabaseHas('dealers', ['code' => 'NX-TEST-001', 'status' => 'pending']);
        $this->assertDatabaseHas('users', ['email' => 'partner@example.test', 'role' => 'dealer']);
        $this->assertDatabaseHas('audit_events', ['action' => 'dealer.created', 'actor_id' => $admin->id]);
    }

    public function test_duplicate_code_is_rejected_without_creating_a_second_user(): void
    {
        Dealer::factory()->create(['code' => 'NX-TEST-001']);
        $count = User::count();
        $this->actingAs($this->admin())->post('/dealers', $this->payload())->assertSessionHasErrors('code');
        $this->assertSame($count + 1, User::count());
    }

    public function test_existing_email_is_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'partner@example.test']);
        $this->actingAs($this->admin())->post('/dealers', [...$this->payload(), 'email' => 'PARTNER@EXAMPLE.TEST'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('dealers', 0);
    }

    public function test_required_coverage_is_validated_for_union_level(): void
    {
        $this->actingAs($this->admin())->post('/dealers', [...$this->payload(), 'level' => 'Union'])->assertSessionHasErrors('upazila');
        $this->assertDatabaseCount('dealers', 0);
    }

    public function test_activation_requires_password_and_records_reason(): void
    {
        $admin = $this->admin();
        $d = Dealer::factory()->pending()->create();
        $this->actingAs($admin)->post("/dealers/{$d->id}/status", ['status' => 'active', 'reason' => 'Eligibility reviewed'])->assertSessionHasErrors('password');
        $this->post("/dealers/{$d->id}/status", ['status' => 'active', 'reason' => 'Eligibility reviewed', 'password' => 'password'])->assertRedirect();
        $this->assertSame('active', $d->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'dealer.status_changed', 'subject_id' => $d->id]);
    }

    public function test_profile_updates_cannot_change_balance_role_or_status(): void
    {
        $d = Dealer::factory()->create();
        $this->actingAs($d->user)->patch("/dealers/{$d->id}", ['business_name' => 'Updated business', 'status' => 'suspended', 'role' => 'admin', 'amount_minor' => 999])->assertRedirect();
        $this->assertSame('active', $d->fresh()->status);
        $this->assertSame('dealer', $d->user->fresh()->role);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_admin_preview_does_not_post_financial_entries(): void
    {
        $this->actingAs($this->admin())->postJson('/rules/preview', ['mrp' => '200.00', 'dealer_minor' => 999999, 'balance' => 999999])->assertOk()->assertJsonPath('dealer_minor', 10000)->assertJsonPath('unallocated_minor', 2400)->assertJsonPath('preview_only', true);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_invalid_preview_amount_returns_422(): void
    {
        $this->actingAs($this->admin())->postJson('/rules/preview', ['mrp' => '200.001'])->assertUnprocessable()->assertJsonValidationErrors('mrp');
    }

    public function test_rule_drafts_are_versioned_and_inactive(): void
    {
        $this->actingAs($this->admin())->post('/rules', ['name' => 'Proposed rules', 'company_bps' => 3000, 'dealer_bps' => 5000, 'pool_bps' => 2000, 'reason' => 'Company review proposal', 'effective_from' => '2026-10-11'])->assertRedirect();
        $this->assertDatabaseHas('rule_versions', ['name' => 'Proposed rules', 'status' => 'draft']);
        $this->assertDatabaseHas('audit_events', ['action' => 'rule.draft_created']);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_invalid_rule_total_is_rejected(): void
    {
        $this->actingAs($this->admin())->post('/rules', ['name' => 'Invalid', 'company_bps' => 3100, 'dealer_bps' => 5000, 'pool_bps' => 2000, 'reason' => 'Company review proposal', 'effective_from' => '2026-10-11'])->assertSessionHasErrors('rates');
        $this->assertDatabaseCount('rule_versions', 0);
    }

    public function test_rank_mode_requires_approved_configuration(): void
    {
        $this->actingAs($this->admin())->post('/operating-mode', ['mode' => 'RANK_BASED', 'revision' => 1, 'reason' => 'Approved company change', 'password' => 'password'])->assertSessionHasErrors('mode');
        $this->assertSame('COMMISSION_BASED', BusinessConfig::find(1)->mode);
    }

    public function test_mode_change_is_audited_and_stale_revision_is_rejected(): void
    {
        $admin = $this->admin();
        RankDefinition::create(['name' => 'Company rank', 'eligibility' => 'Eligibility approved by company review', 'status' => 'approved', 'effect' => 'status_only', 'actor_id' => $admin->id]);
        $this->actingAs($admin)->post('/operating-mode', ['mode' => 'RANK_BASED', 'revision' => 1, 'reason' => 'Approved company change', 'password' => 'password'])->assertRedirect();
        $this->assertSame('RANK_BASED', BusinessConfig::find(1)->mode);
        $this->assertDatabaseHas('audit_events', ['action' => 'mode.changed']);
        $this->post('/operating-mode', ['mode' => 'COMMISSION_BASED', 'revision' => 1, 'reason' => 'Stale concurrent request', 'password' => 'password'])->assertSessionHasErrors('mode');
        $this->assertSame(2, BusinessConfig::find(1)->mode_revision);
    }

    public function test_outstanding_payout_blocks_mode_transition(): void
    {
        $admin = $this->admin();
        $d = Dealer::factory()->create();
        RankDefinition::create(['name' => 'Company rank', 'eligibility' => 'Company approved criteria', 'status' => 'approved', 'actor_id' => $admin->id]);
        Payout::create(['dealer_id' => $d->id, 'amount_minor' => 100, 'status' => 'needs_reconciliation', 'idempotency_key' => 'test-key']);
        $this->actingAs($admin)->post('/operating-mode', ['mode' => 'RANK_BASED', 'revision' => 1, 'reason' => 'Company change requested', 'password' => 'password'])->assertSessionHasErrors('mode');
        $this->assertSame(1, BusinessConfig::find(1)->mode_revision);
    }

    public function test_company_settings_persist_without_mode_tampering(): void
    {
        $this->actingAs($this->admin())->patch('/settings', ['company_name' => 'Our Company', 'contact_email' => 'hello@example.test', 'contact_phone' => '', 'mode' => 'RANK_BASED'])->assertRedirect();
        $this->assertDatabaseHas('business_config', ['company_name' => 'Our Company', 'mode' => 'COMMISSION_BASED']);
        $this->assertDatabaseHas('audit_events', ['action' => 'company.updated']);
    }

    public function test_exports_are_minimized_and_audited(): void
    {
        Dealer::factory()->create(['mobile' => '+8801712345678']);
        $res = $this->actingAs($this->admin())->get('/reports/export?type=dealers')->assertOk()->assertDownload();
        $content = $res->streamedContent();
        $this->assertStringNotContainsString('+8801712345678', $content);
        $this->assertStringContainsString('code,level,division', $content);
        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported']);
    }

    public function test_selected_draft_preview_uses_its_rates_without_activating_it(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/rules', ['name' => 'Alternative split', 'company_bps' => 4000, 'dealer_bps' => 4000, 'pool_bps' => 2000, 'reason' => 'Review an alternative split', 'effective_from' => '2026-10-11'])->assertRedirect();
        $version = RuleVersion::firstOrFail();
        $this->postJson('/rules/preview', ['mrp' => '200.00', 'version_id' => $version->id])->assertOk()->assertJsonPath('company_minor', 8000)->assertJsonPath('dealer_minor', 8000)->assertJsonPath('version_id', $version->id);
        $this->postJson('/rules/preview', ['mrp' => '200.00'])->assertJsonPath('company_minor', 6000);
        $this->assertSame('draft', $version->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_reserved_funds_block_mode_change_even_without_payout_record(): void
    {
        $admin = $this->admin();
        $dealer = Dealer::factory()->create();
        RankDefinition::create(['name' => 'Company rank', 'eligibility' => 'Company approved eligibility criteria', 'status' => 'approved', 'effect' => 'status_only', 'actor_id' => $admin->id]);
        LedgerEntry::create(['dealer_id' => $dealer->id, 'kind' => 'reserved', 'amount_minor' => -1000, 'business_reference' => 'reserve-without-payout', 'reason' => 'Unreconciled reservation', 'created_at' => now()]);
        $this->actingAs($admin)->post('/operating-mode', ['mode' => 'RANK_BASED', 'revision' => 1, 'reason' => 'Approved company change', 'password' => 'password'])->assertSessionHasErrors('mode');
        $this->assertSame('COMMISSION_BASED', BusinessConfig::find(1)->mode);
        $this->assertDatabaseCount('payouts', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
    }
}
