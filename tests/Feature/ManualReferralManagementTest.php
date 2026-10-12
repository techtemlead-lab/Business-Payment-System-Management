<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Dealer;
use App\Models\DealershipRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManualReferralManagementTest extends TestCase
{
    use RefreshDatabase;

    private function accountData(string $code = 'NEW-DEALER'): array
    {
        return ['name' => 'New dealer', 'email' => strtolower($code).'@example.test', 'code' => $code, 'level' => 'District', 'division' => 'Dhaka', 'district' => 'Dhaka', 'password' => 'Secure-Dealer-2026!'];
    }

    public function test_lookup_is_company_only_and_returns_limited_identity_without_private_contact_data(): void
    {
        $dealer = Dealer::factory()->create(['code' => 'UNIQUE-REF']);
        $this->get('/referrals/options')->assertRedirect('/login');
        $this->actingAs($dealer->user)->get('/referrals/options')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/referrals/options?q=unique-ref')->assertOk()->assertExactJson(['dealers' => [['code' => $dealer->code, 'name' => $dealer->user->name, 'status' => 'active']]]);
        $this->get('/referrals/options?q=UNIQUE-REF&exclude='.$dealer->id)->assertExactJson(['dealers' => []]);
        Dealer::factory()->count(21)->create();
        $this->get('/referrals/options')->assertJsonCount(20, 'dealers');
    }

    public function test_company_must_confirm_a_referred_account_and_unique_code_is_retained(): void
    {
        $referrer = Dealer::factory()->create(['code' => 'REF-001']);
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [...$this->accountData(), 'referral_choice' => 'referred', 'referrer_code' => ' ref-001 '];
        $this->actingAs($admin)->post('/dealers', $payload)->assertSessionHasErrors('referral_verified');
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->post('/dealers', [...$payload, 'referral_verified' => true, 'referrer_code' => 'NONEXISTENT'])->assertSessionHasErrors('referrer_code');
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->post('/dealers', [...$payload, 'referral_verified' => true])->assertRedirect('/dealers');
        $dealer = Dealer::where('code', 'NEW-DEALER')->firstOrFail();
        $this->assertSame($referrer->id, $dealer->referrer_id);
        $this->assertSame($admin->id, $dealer->referral_verified_by);
        $this->assertNotNull($dealer->referral_verified_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'dealer.referrer_changed', 'subject_id' => $dealer->id, 'actor_id' => $admin->id]);
        $this->post('/dealers', [...$this->accountData(), 'email' => 'duplicate@example.test'])->assertSessionHasErrors('code');
        $this->get('/dealers')->assertInertia(fn (Assert $page) => $page->where('dealers.data.0.code', 'NEW-DEALER')->where('dealers.data.0.referrer_code', 'REF-001')->where('dealers.data.0.referral_verified_by_name', $admin->name));
    }

    public function test_without_referral_never_automatically_uses_an_application_suggestion(): void
    {
        $parent = Dealer::factory()->create();
        $application = DealershipRequest::factory()->create(['referrer_id' => $parent->id, 'referral_code' => $parent->code]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/dealers', [...$this->accountData(), 'application_id' => $application->id, 'referral_choice' => 'none', 'referrer_code' => '', 'referral_verified' => false, 'referrer_id' => $parent->id])->assertRedirect('/dealers');
        $dealer = Dealer::where('code', 'NEW-DEALER')->firstOrFail();
        $this->assertNull($dealer->referrer_id);
        $this->assertNull($dealer->referral_verified_at);
        $this->assertNull($dealer->referral_verified_by);
        $this->assertSame($parent->code, $application->fresh()->referral_code);
    }

    public function test_company_can_add_verify_change_and_remove_referrals_with_an_audit_trail(): void
    {
        $dealer = Dealer::factory()->create();
        $first = Dealer::factory()->create(['code' => 'FIRST']);
        $second = Dealer::factory()->create(['code' => 'SECOND']);
        $child = Dealer::factory()->create(['referrer_id' => $dealer->id]);
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['referral_choice' => 'referred', 'referral_verified' => true, 'reason' => 'Manually confirmed by company', 'password' => 'password'];
        $url = '/dealers/'.$dealer->id.'/referrer';
        $this->actingAs($admin)->patch($url, [...$data, 'referrer_code' => 'FIRST'])->assertRedirect();
        $this->assertSame($first->id, $dealer->fresh()->referrer_id);
        $this->patch($url, [...$data, 'referrer_code' => 'FIRST'])->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['action' => 'dealer.referrer_verified', 'subject_id' => $dealer->id]);
        $this->patch($url, [...$data, 'referrer_code' => 'SECOND'])->assertRedirect();
        $this->assertSame($second->id, $dealer->fresh()->referrer_id);
        $this->get('/dealers/'.$dealer->id)->assertInertia(fn (Assert $page) => $page->where('dealer.referrer_code', 'SECOND')->where('dealer.referrer_name', $second->user->name)->where('dealer.referral_verified_by_name', $admin->name));
        $this->patch($url, [...$data, 'referral_choice' => 'none', 'referrer_code' => '', 'referral_verified' => false])->assertRedirect();
        $this->assertNull($dealer->fresh()->referrer_id);
        $this->assertNull($dealer->fresh()->referral_verified_at);
        $this->assertNull($dealer->fresh()->referral_verified_by);
        $this->assertSame($dealer->id, $child->fresh()->referrer_id);
        $this->assertSame(3, AuditEvent::where('action', 'dealer.referrer_changed')->where('subject_id', $dealer->id)->count());
    }

    public function test_unverified_changes_missing_credentials_and_inconsistent_choices_are_rejected(): void
    {
        $dealer = Dealer::factory()->create();
        $referrer = Dealer::factory()->create();
        $data = ['referral_choice' => 'referred', 'referrer_code' => $referrer->code, 'reason' => 'Manual connection verification'];
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch('/dealers/'.$dealer->id.'/referrer', $data)->assertSessionHasErrors('password');
        $this->patch('/dealers/'.$dealer->id.'/referrer', [...$data, 'password' => 'password'])->assertSessionHasErrors('referral_verified');
        $this->post('/dealers', [...$this->accountData(), 'referral_choice' => 'referred', 'referrer_code' => ''])->assertSessionHasErrors('referrer_code');
        $this->post('/dealers', [...$this->accountData(), 'referral_choice' => 'none', 'referrer_code' => $referrer->code, 'referral_verified' => true])->assertSessionHasErrors('referrer_code');
        $this->patch('/dealers/'.$dealer->id.'/referrer', [...$data, 'password' => 'password', 'referral_verified' => true, 'referrer_code' => 'NONEXISTENT'])->assertSessionHasErrors('referrer_code');
        $this->assertNull($dealer->fresh()->referrer_id);
        $this->assertDatabaseMissing('dealers', ['code' => 'NEW-DEALER']);
    }
}
