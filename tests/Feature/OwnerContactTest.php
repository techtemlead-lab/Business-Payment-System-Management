<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $owner = User::factory()->staff($role)->create(['password' => 'OwnerPassword12345', 'name' => 'Owner Name', 'phone' => '01710000001']);
        $previousEmail = $owner->email;
        DB::table('password_reset_tokens')->insert(['email' => $previousEmail, 'token' => 'obsolete-reset-token', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 'other-owner-session', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $this->actingAs($owner)->from('/security')->post('/commands/owner/contact', [
            'name' => 'Updated Owner',
            'email' => 'new-owner@example.com',
            'phone' => '01710000099',
            'current_password' => 'OwnerPassword12345',
            'role' => 'dealer', 'status' => 'suspended',
        ])->assertRedirect('/security')->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $owner->id,
            'name' => 'Updated Owner',
            'email' => 'new-owner@example.com',
            'phone' => '01710000099',
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => null,
        ]);
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
        $this->actingAs($owner)->get('/company/sub-dealer-orders')
            ->assertRedirect('/orders?buyer_role=sub_dealer');
        $this->actingAs($owner)->get('/company/orders?buyer_role=sub_dealer')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'orders')
                ->where('filters.buyer_role', 'sub_dealer'));
        $this->actingAs($owner)->get('/company/ledger')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'ledger'));
        $this->actingAs($owner)->get('/company/salary')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'salary'));
    }

    public function test_owner_profile_page_exposes_self_edit_form(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create(['name' => 'Super Admin']);

        $this->actingAs($owner)->get('/company/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'profile')
                ->where('auth.user.name', 'Super Admin')
                ->where('auth.user.role', 'super_admin'));
    }

    public function test_management_orders_can_filter_by_buyer_role(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create();
        Order::create([
            'user_id' => $dealer->id,
            'number' => 'ORD-DEALER-1',
            'status' => 'submitted',
            'total' => '100.00',
            'idempotency_key' => 'dealer-order-key',
            'payload_hash' => hash('sha256', 'dealer'),
        ]);
        Order::create([
            'user_id' => $subDealer->id,
            'number' => 'ORD-SUB-1',
            'status' => 'submitted',
            'total' => '200.00',
            'idempotency_key' => 'sub-order-key',
            'payload_hash' => hash('sha256', 'sub'),
        ]);

        $this->actingAs($owner)->get('/company/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.orders.data', 2));
        $this->actingAs($owner)->get('/company/orders?buyer_role=dealer')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', 'ORD-DEALER-1')
                ->where('data.orders.data.0.user.role', 'dealer'));
        $this->actingAs($owner)->get('/company/orders?buyer_role=sub_dealer')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', 'ORD-SUB-1')
                ->where('data.orders.data.0.user.role', 'sub_dealer'));
    }

    public function test_management_orders_can_filter_by_date_status_and_partner_name(): void
    {
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 15:00:00', 'Asia/Dhaka'));
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create(['name' => 'Rangpur Dealer', 'phone' => '01711111111']);
        $subDealer = User::factory()->subDealer()->create(['name' => 'Dhaka Sub']);
        Order::create([
            'user_id' => $dealer->id,
            'number' => 'ORD-TODAY',
            'status' => 'submitted',
            'total' => '100.00',
            'idempotency_key' => 'today-order-key',
            'payload_hash' => hash('sha256', 'today'),
        ]);
        Order::create([
            'user_id' => $subDealer->id,
            'number' => 'ORD-OLDER',
            'status' => 'approved',
            'total' => '200.00',
            'idempotency_key' => 'older-order-key',
            'payload_hash' => hash('sha256', 'older'),
        ]);
        DB::table('orders')->where('number', 'ORD-TODAY')->update([
            'created_at' => CarbonImmutable::parse('2026-11-03 09:00:00', 'Asia/Dhaka')->utc(),
        ]);
        DB::table('orders')->where('number', 'ORD-OLDER')->update([
            'created_at' => CarbonImmutable::parse('2026-10-15 09:00:00', 'Asia/Dhaka')->utc(),
        ]);

        $this->actingAs($owner)->get('/company/orders?period=today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', 'ORD-TODAY')
                ->where('filters.period', 'today'));
        $this->actingAs($owner)->get('/company/orders?period=week')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', 'ORD-TODAY'));
        $this->actingAs($owner)->get('/company/orders?period=month')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', 'ORD-TODAY'));
        $this->actingAs($owner)->get('/company/orders?period=day&from=2026-10-15')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', 'ORD-OLDER')
                ->where('filters.from', '2026-10-15'));
        $this->actingAs($owner)->get('/company/orders?period=custom&from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', 'ORD-OLDER'));
        $this->actingAs($owner)->get('/company/orders?status=approved')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', 'ORD-OLDER'));
        $this->actingAs($owner)->get('/company/orders?q=Rangpur')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.number', 'ORD-TODAY'));
        $this->from('/company/orders')->get('/company/orders?period=year')->assertSessionHasErrors('period');
    }
}
