<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_bangladesh_phone_formats_log_in_the_registered_staff_account(): void
    {
        $u = User::factory()->create(['role' => 'finance', 'phone' => '+880 (10) 0000-0001', 'password' => 'SyntheticPassword123']);
        foreach (['01000000001', '+8801000000001', '008801000000001', '010 0000 0001'] as $phone) {
            $this->post('/company/login', ['email' => $phone, 'password' => 'SyntheticPassword123'])->assertRedirect('/');
            $this->assertAuthenticatedAs($u);
            $this->post('/logout');
        }
    }

    public function test_dealer_and_subdealer_phone_sign_in_preserves_company_separation(): void
    {
        foreach (['dealer' => 'dealer', 'sub_dealer' => 'subdealer'] as $role => $portal) {
            $u = User::factory()->create(['role' => $role, 'phone' => '01000000002', 'password' => 'SyntheticPassword123']);
            $this->post('/company/login', ['email' => '01000000002', 'password' => 'SyntheticPassword123'])->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->onPortal($portal)->post('/login', ['email' => '+8801000000002', 'password' => 'SyntheticPassword123'])->assertRedirect('/');
            $this->assertAuthenticatedAs($u);
            $this->post('/logout');
            $u->update(['phone' => null]);
        }
    }

    public function test_normalized_phone_uniqueness_blocks_ambiguous_accounts_and_email_still_works(): void
    {
        $u = User::factory()->create(['role' => 'dealer', 'phone' => '01000000003', 'email' => 'phone-fallback@example.test', 'password' => 'SyntheticPassword123']);

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['role' => 'dealer', 'phone' => '+8801000000003']);
    }

    public function test_invalid_phone_is_rejected_and_email_still_works(): void
    {
        $u = User::factory()->create(['role' => 'dealer', 'phone' => '01000000006', 'email' => 'phone-fallback@example.test', 'password' => 'SyntheticPassword123']);
        foreach (['letters01000000006', 'not-an-identity'] as $phone) {
            $this->post('/login', ['email' => $phone, 'password' => 'SyntheticPassword123'])->assertSessionHasErrors('email');
            $this->assertGuest();
        }
        $this->post('/login', ['email' => 'PHONE-FALLBACK@example.test', 'password' => 'SyntheticPassword123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
    }

    public function test_suspended_account_and_wrong_password_cannot_authenticate_by_phone(): void
    {
        $u = User::factory()->create(['role' => 'super_admin', 'phone' => '01000000004', 'status' => 'suspended', 'password' => 'SyntheticPassword123']);
        $this->post('/company/login', ['email' => '01000000004', 'password' => 'SyntheticPassword123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $u->forceFill(['status' => 'active'])->save();
        $this->post('/company/login', ['email' => '01000000004', 'password' => 'WrongPassword123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_phone_format_changes_do_not_bypass_attempt_limit(): void
    {
        User::factory()->create(['role' => 'dealer', 'phone' => '01000000005', 'password' => 'SyntheticPassword123']);
        foreach (['01000000005', '+8801000000005', '008801000000005', '010 0000 0005', '+880 (10) 0000-0005'] as $phone) {
            $this->post('/login', ['email' => $phone, 'password' => 'WrongPassword123'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => '01000000005', 'password' => 'SyntheticPassword123'])->assertSessionHasErrors(['email' => 'Too many attempts. Please wait a minute.']);
        $this->assertGuest();
    }
}
