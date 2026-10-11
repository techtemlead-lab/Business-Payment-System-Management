<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Totp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_cannot_read_or_edit_another_dealer_record(): void
    {
        $a = Dealer::factory()->create();
        $b = Dealer::factory()->create();
        $this->actingAs($a->user)->get("/dealers/{$b->id}")->assertNotFound();
        $this->patch("/dealers/{$b->id}", ['business_name' => 'Intrusion'])->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'access.denied']);
    }

    public function test_dealer_cannot_call_admin_pages_or_mutations(): void
    {
        $d = Dealer::factory()->create();
        $this->actingAs($d->user)->get('/dealers')->assertForbidden();
        $this->post('/dealers', ['name' => 'Intrusion'])->assertForbidden();
        $this->post('/operating-mode', ['mode' => 'RANK_BASED'])->assertForbidden();
        $this->get('/reports/export?type=dealers')->assertForbidden();
        $this->assertDatabaseCount('dealers', 1);
    }

    public function test_dealer_ledger_is_scoped_to_session_even_with_tampered_identifier(): void
    {
        $a = Dealer::factory()->create();
        $b = Dealer::factory()->create();
        foreach ([$a, $b] as $d) {
            LedgerEntry::create(['dealer_id' => $d->id, 'amount_minor' => 100, 'kind' => 'earned', 'business_reference' => 'earned-'.$d->id, 'reason' => 'Test fixture only', 'created_at' => now()]);
        }
        $this->actingAs($a->user)->get('/ledger?dealer_id='.$b->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Ledger')->has('entries.data', 1)->where('entries.data.0.dealer_id', $a->id));
    }

    public function test_suspended_dealer_loses_workspace_access(): void
    {
        $d = Dealer::factory()->create(['status' => 'suspended']);
        $this->actingAs($d->user)->get('/dashboard')->assertForbidden();
    }

    public function test_no_public_registration_route_exists(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_demo_access_is_unavailable_without_local_opt_in(): void
    {
        config(['dealer.demo_enabled' => true]);
        $this->post('/demo-login', ['role' => 'admin'])->assertNotFound();
    }

    public function test_password_login_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'unknown@example.test', 'password' => 'invalid'])->assertSessionHasErrors('email');
        }$this->post('/login', ['email' => 'unknown@example.test', 'password' => 'invalid'])->assertTooManyRequests();
    }

    public function test_active_dealer_can_sign_in_with_normalized_email(): void
    {
        $d = Dealer::factory()->create();
        $this->post('/login', ['email' => strtoupper($d->user->email), 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($d->user);
    }

    public function test_pending_dealer_cannot_sign_in(): void
    {
        $d = Dealer::factory()->pending()->create();
        $this->post('/login', ['email' => $d->user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_mfa_guard_blocks_workspace_until_verification(): void
    {
        $u = User::factory()->create(['role' => 'admin', 'totp_secret' => (new Totp)->secret()]);
        $this->actingAs($u)->get('/dashboard')->assertRedirect('/two-factor');
    }

    public function test_totp_matches_official_rfc6238_sha1_vectors(): void
    {
        $t = new Totp;
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('94287082', $t->code($secret, 59, 8));
        $this->assertSame('07081804', $t->code($secret, 1111111109, 8));
        $this->assertSame('69279037', $t->code($secret, 2000000000, 8));
    }

    public function test_mfa_enrollment_encrypts_secret_and_does_not_audit_secret(): void
    {
        $u = User::factory()->create(['role' => 'admin']);
        $secret = (new Totp)->secret();
        $this->actingAs($u)->withSession(['totp_setup_secret' => $secret])->post('/security/enable', ['code' => (new Totp)->code($secret, time())])->assertRedirect();
        $this->assertNotSame($secret, $u->fresh()->getRawOriginal('totp_secret'));
        $this->assertSame($secret, $u->fresh()->totp_secret);
        $this->assertArrayNotHasKey('totp_secret', $u->fresh()->toArray());
        $this->assertSame(8, count($u->fresh()->recovery_codes));
        $this->assertDatabaseHas('audit_events', ['action' => 'security.mfa_enabled']);
    }

    public function test_used_authenticator_code_cannot_be_replayed(): void
    {
        $secret = (new Totp)->secret();
        $u = User::factory()->create(['role' => 'admin', 'totp_secret' => $secret]);
        $code = (new Totp)->code($secret, time());
        $this->actingAs($u)->post('/two-factor', ['code' => $code])->assertRedirect('/dashboard');
        $this->withSession(['mfa_verified' => false])->post('/two-factor', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_recovery_code_is_single_use(): void
    {
        $u = User::factory()->create(['role' => 'admin', 'totp_secret' => (new Totp)->secret(), 'recovery_codes' => [Hash::make('single-use-recovery')]]);
        $this->actingAs($u)->post('/two-factor', ['code' => 'single-use-recovery'])->assertRedirect('/dashboard');
        $this->assertSame([], $u->fresh()->recovery_codes);
        $this->post('/two-factor', ['code' => 'single-use-recovery'])->assertSessionHasErrors('code');
    }

    public function test_password_reset_sends_token_and_invalidates_old_password(): void
    {
        Notification::fake();
        $u = User::factory()->create();
        $this->post('/forgot-password', ['email' => $u->email])->assertRedirect();
        Notification::assertSentTo($u, ResetPassword::class);
        $token = Password::createToken($u);
        $this->post('/reset-password', ['email' => $u->email, 'token' => $token, 'password' => 'New-Secure-Password2026!', 'password_confirmation' => 'New-Secure-Password2026!'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('New-Secure-Password2026!', $u->fresh()->password));
        $this->assertFalse(Hash::check('password', $u->fresh()->password));
    }

    public function test_admin_gate_and_dealer_policy_enforce_roles_and_ownership(): void
    {
        $d = Dealer::factory()->create();
        $other = Dealer::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($admin->can('admin'));
        $this->assertFalse($d->user->can('admin'));
        $this->assertTrue($d->user->can('view', $d));
        $this->assertFalse($d->user->can('view', $other));
        $this->assertTrue($admin->can('view', $other));
    }
}
