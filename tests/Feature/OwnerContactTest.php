<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OwnerContactTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['super_admin'])]
    #[TestWith(['md'])]
    #[TestWith(['chairman'])]
    public function test_owner_can_replace_the_login_email_after_password_confirmation_without_changing_role(string $role): void
    {
        $owner = User::factory()->staff($role)->create(['password' => 'OwnerPassword12345']);
        $previousEmail = $owner->email;
        DB::table('password_reset_tokens')->insert(['email' => $previousEmail, 'token' => 'obsolete-reset-token', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 'other-owner-session', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $this->actingAs($owner)->from('/security')->post('/commands/owner/contact', [
            'email' => 'new-owner@example.com', 'current_password' => 'OwnerPassword12345',
            'role' => 'dealer', 'status' => 'suspended',
        ])->assertRedirect('/security')->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'email' => 'new-owner@example.com', 'role' => $role, 'status' => 'active', 'email_verified_at' => null]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $previousEmail]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-owner-session']);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $owner->id, 'action' => 'owner.contact_updated']);
    }

    public function test_email_change_rejects_wrong_password_and_duplicate_email_without_mutation(): void
    {
        $owner = User::factory()->staff()->create(['password' => 'OwnerPassword12345']);
        $other = User::factory()->create();
        $this->actingAs($owner)->post('/commands/owner/contact', ['email' => 'new-owner@example.com', 'current_password' => 'WrongPassword123'])
            ->assertSessionHasErrors('current_password');
        $this->post('/commands/owner/contact', ['email' => $other->email, 'current_password' => 'OwnerPassword12345'])
            ->assertSessionHasErrors('email');
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'email' => $owner->email]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'owner.contact_updated']);
    }

    #[TestWith(['finance'])]
    #[TestWith(['dealer'])]
    #[TestWith(['sub_dealer'])]
    public function test_other_roles_cannot_use_owner_contact_changes(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->post('/commands/owner/contact', ['email' => 'unauthorized@example.com', 'current_password' => 'password'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    public function test_guest_cannot_change_owner_contact(): void
    {
        $this->post('/commands/owner/contact', ['email' => 'guest@example.com'])->assertRedirect('/login');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_owner_dealer_directory_contains_only_partner_accounts(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create();
        User::factory()->dealer()->create();
        User::factory()->subDealer()->create();
        User::factory()->staff('finance')->create();

        $this->actingAs($owner)->get('/company/accounts')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.accounts.data', 1)
                ->where('data.accounts.data.0.role', 'dealer'));

        $this->actingAs($owner)->get('/company/sub-dealers')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.accounts.data', 1)
                ->where('data.accounts.data.0.role', 'sub_dealer'));
    }

    public function test_owner_can_open_management_orders_and_finance_pages(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->get('/company/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'orders'));
        $this->actingAs($owner)->get('/company/sub-dealer-orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'sub-dealer-orders'));
        $this->actingAs($owner)->get('/company/ledger')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'ledger'));
        $this->actingAs($owner)->get('/company/salary')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'salary'));
    }
}
