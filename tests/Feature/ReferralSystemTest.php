<?php

namespace Tests\Feature;

use App\Models\BusinessConfig;
use App\Models\Dealer;
use App\Models\DealershipRequest;
use App\Models\Purchase;
use App\Models\RuleVersion;
use App\Models\User;
use App\Services\FinancialCalculator;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReferralSystemTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function purchase(Dealer $dealer, string $reference): array
    {
        return ['dealer_code' => $dealer->code, 'reference' => $reference, 'product_name' => 'Test product', 'quantity' => 2, 'unit_mrp' => '500.00', 'amount_paid' => '800.00', 'purchased_on' => now()->toDateString(), 'password' => 'password'];
    }

    public function test_network_tracks_exactly_three_generations_and_dealer_cannot_select_another_root(): void
    {
        $root = Dealer::factory()->create(['code' => 'ROOT']);
        $p1 = Dealer::factory()->create(['code' => 'P1', 'referrer_id' => $root->id]);
        $p2 = Dealer::factory()->create(['code' => 'P2', 'referrer_id' => $p1->id]);
        $p3 = Dealer::factory()->create(['code' => 'P3', 'referrer_id' => $p2->id]);
        Dealer::factory()->create(['code' => 'P4', 'referrer_id' => $p3->id]);
        $other = Dealer::factory()->create(['code' => 'OTHER']);
        $this->actingAs($root->user)->get('/referrals?generation=3&code=OTHER')->assertInertia(fn (Assert $page) => $page->where('root.code', 'ROOT')->where('counts', [1, 1, 1])->has('dealers.data', 1)->where('dealers.data.0.code', 'P3')->missing('dealers.data.0.email'));
        $this->get('/referrals?generation=1')->assertInertia(fn (Assert $page) => $page->where('dealers.data.0.code', 'P1')->where('referralLink', url('/request-dealership').'?ref=ROOT'));
        $this->actingAs($this->admin())->get('/referrals?code=P3')->assertInertia(fn (Assert $page) => $page->where('ancestors.0.code', 'P2')->where('ancestors.1.code', 'P1')->where('ancestors.2.code', 'ROOT'));
    }

    public function test_company_referrer_changes_reject_self_referrals_and_cycles_beyond_three_levels(): void
    {
        $root = Dealer::factory()->create(['code' => 'ROOT']);
        $last = $root;
        for ($generation = 1; $generation <= 5; $generation++) {
            $last = Dealer::factory()->create(['referrer_id' => $last->id]);
        }
        $this->actingAs($this->admin())->patch('/dealers/'.$root->id.'/referrer', ['referral_verified' => true, 'referrer_code' => $root->code, 'reason' => 'Self referral test', 'password' => 'password'])->assertSessionHasErrors('referrer_code');
        $this->patch('/dealers/'.$root->id.'/referrer', ['referral_verified' => true, 'referrer_code' => $last->code, 'reason' => 'Long cycle test', 'password' => 'password'])->assertSessionHasErrors('referrer_code');
        $this->assertNull($root->fresh()->referrer_id);
        $this->patch('/dealers/'.$last->id.'/referrer', ['referrer_code' => '', 'reason' => 'Correct previous relationship', 'password' => 'password'])->assertRedirect();
        $this->assertNull($last->fresh()->referrer_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'dealer.referrer_changed', 'subject_id' => $last->id]);
    }

    public function test_dealers_cannot_change_referrers_or_commission_versions(): void
    {
        $dealer = Dealer::factory()->create();
        $version = RuleVersion::create(['name' => 'Test', 'status' => 'draft', 'definition' => FinancialCalculator::definition(), 'reason' => 'Test rule']);
        $this->get('/referrals')->assertRedirect('/login');
        $this->actingAs($dealer->user)->patch('/dealers/'.$dealer->id.'/referrer', ['reason' => 'Unauthorized request'])->assertForbidden();
        $this->post('/rules/'.$version->id.'/activate', ['revision' => 1, 'reason' => 'Unauthorized change'])->assertForbidden();
        $this->post('/rules', [])->assertForbidden();
    }

    public function test_application_referrer_is_preserved_when_company_creates_an_account(): void
    {
        $root = Dealer::factory()->create(['code' => 'REF-ROOT']);
        $this->post('/request-dealership', ['name' => 'Applicant', 'email' => 'applicant@example.test', 'mobile' => '+8801712345678', 'business_name' => 'Applicant business', 'business_address' => 'Dhaka', 'level' => 'District', 'division' => 'Dhaka', 'district' => 'Dhaka', 'consent' => true, 'referral_code' => 'ref-root', 'referrer_id' => 99999])->assertRedirect('/request-dealership');
        $application = DealershipRequest::firstOrFail();
        $this->assertSame($root->id, (int) $application->referrer_id);
        $this->assertSame('REF-ROOT', $application->referral_code);
        $this->actingAs($this->admin())->post('/dealers', ['name' => 'Applicant', 'email' => 'applicant@example.test', 'code' => 'REF-NEW', 'level' => 'District', 'division' => 'Dhaka', 'district' => 'Dhaka', 'password' => 'Secure-Dealer-2026!', 'application_id' => $application->id, 'referrer_code' => $root->code, 'referral_verified' => true])->assertRedirect('/dealers');
        $this->assertDatabaseHas('dealers', ['code' => 'REF-NEW', 'referrer_id' => $root->id, 'dealership_request_id' => $application->id, 'status' => 'pending']);
        $this->assertSame('reviewed', $application->fresh()->status);
    }

    public function test_new_purchase_snapshots_three_ancestors_without_posting_referral_earnings(): void
    {
        $root = Dealer::factory()->create(['code' => 'ROOT']);
        $p1 = Dealer::factory()->create(['code' => 'P1', 'referrer_id' => $root->id]);
        $p2 = Dealer::factory()->create(['code' => 'P2', 'referrer_id' => $p1->id]);
        $p3 = Dealer::factory()->create(['code' => 'P3', 'referrer_id' => $p2->id]);
        $this->actingAs($this->admin())->post('/purchases', $this->purchase($p3, 'REF-PURCHASE'))->assertRedirect();
        $purchase = Purchase::firstOrFail();
        $this->assertSame(['P2', 'P1', 'ROOT'], array_column($purchase->referral_snapshot, 'code'));
        $this->assertFalse($purchase->calculation['referrals']['posting_enabled']);
        $this->assertDatabaseCount('ledger_entries', 1);
        app(ReferralService::class)->assign($p3->id, $root->code, 'Company relationship correction');
        $this->assertSame(['P2', 'P1', 'ROOT'], array_column($purchase->fresh()->referral_snapshot, 'code'));
        $this->post('/purchases', $this->purchase($p3, 'REF-PURCHASE'))->assertRedirect();
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->post('/purchases/'.$purchase->id.'/cancel', ['reason' => 'Cancel test purchase', 'password' => 'password'])->assertRedirect();
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_editable_percentages_and_activation_affect_only_new_purchases(): void
    {
        $dealer = Dealer::factory()->create();
        $this->actingAs($this->admin())->post('/purchases', $this->purchase($dealer, 'OLD-RULE'))->assertRedirect();
        $old = Purchase::firstOrFail();
        $rewards = FinancialCalculator::REWARDS;
        $rewards['Referral bonus'] = 1500;
        $this->post('/rules', ['name' => 'Company adjusted percentages', 'company_bps' => 4000, 'dealer_bps' => 4000, 'pool_bps' => 2000, 'rewards' => $rewards, 'referral_bps' => [500, 300, 100], 'effective_from' => now()->toDateString(), 'reason' => 'Company approved allocation review'])->assertRedirect();
        $version = RuleVersion::where('name', 'Company adjusted percentages')->firstOrFail();
        $this->assertSame(1500, $version->definition['rewards']['Referral bonus']);
        $this->assertSame([500, 300, 100], $version->definition['referrals']['generation_bps']);
        $this->assertFalse($version->definition['referrals']['posting_enabled']);
        $this->post('/rules/'.$version->id.'/activate', ['revision' => 1, 'reason' => 'Approved for new purchases'])->assertSessionHasErrors('password');
        $this->post('/rules/'.$version->id.'/activate', ['revision' => 1, 'reason' => 'Approved for new purchases', 'password' => 'password'])->assertRedirect();
        $this->assertSame($version->id, (int) BusinessConfig::find(1)->active_rule_version_id);
        $this->post('/purchases', $this->purchase($dealer, 'NEW-RULE'))->assertRedirect();
        $new = Purchase::where('reference', 'NEW-RULE')->firstOrFail();
        $this->assertSame(40000, $new->calculation['dealer_minor']);
        $this->assertSame(50000, $old->fresh()->calculation['dealer_minor']);
        $this->postJson('/rules/preview', ['mrp' => '1000.00'])->assertJsonPath('dealer_minor', 40000);
        $this->post('/rules/'.$version->id.'/activate', ['revision' => 1, 'reason' => 'Stale approval', 'password' => 'password'])->assertSessionHasErrors('revision');
        $this->assertDatabaseHas('audit_events', ['action' => 'rule.activated']);
    }

    public function test_invalid_reward_allocations_and_future_rule_activation_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $payload = ['name' => 'Bad rewards', 'company_bps' => 3000, 'dealer_bps' => 5000, 'pool_bps' => 2000, 'effective_from' => now()->toDateString(), 'reason' => 'Invalid allocation test'];
        $this->post('/rules', [...$payload, 'rewards' => ['Referral bonus' => 10000]])->assertSessionHasErrors('rates');
        $this->post('/rules', [...$payload, 'referral_bps' => [0, 0, 10001]])->assertSessionHasErrors('referral_bps.2');
        $version = RuleVersion::create(['name' => 'Future', 'status' => 'draft', 'definition' => FinancialCalculator::definition(), 'effective_from' => now()->addDay()->toDateString(), 'reason' => 'Future rule']);
        $this->post('/rules/'.$version->id.'/activate', ['revision' => 1, 'reason' => 'Early activation attempt', 'password' => 'password'])->assertSessionHasErrors('version');
        $this->assertNull(BusinessConfig::find(1)->active_rule_version_id);
    }
}
