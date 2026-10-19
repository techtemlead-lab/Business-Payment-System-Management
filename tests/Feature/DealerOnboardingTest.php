<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DealerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_accounts_create_page_requires_accounts_capability(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->create(['role' => 'dealer']);
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($dealer)->get('/company/accounts/create')->assertForbidden();
        $this->actingAs($editor)->get('/company/accounts/create')->assertForbidden();

        $this->actingAs($admin)->get('/company/accounts/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AccountCreate')
                ->has('roles')
                ->has('sponsors')
            );
    }

    public function test_staff_can_create_dealer_with_manual_code_and_pdf_kyc_fields(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'super_admin']);

        $payload = [
            'role' => 'dealer',
            'dealer_code' => 'D-DHK-001',
            'name' => 'Md. Rafiqul Islam',
            'name_bn' => 'মোঃ রফিকুল ইসলাম',
            'email' => 'rafiq@example.com',
            'phone' => '01712345678',
            'father_name' => 'Late Abdur Rahman',
            'mother_name' => 'Rahima Begum',
            'nid' => '19851234567890123',
            'pharmacy' => 'Madina Care Point Gazipur',
            'division' => 'Dhaka',
            'district' => 'Gazipur',
            'upazila' => 'Kaliakair',
            'union' => 'Chapair',
            'village' => 'Ratanpur',
            'post_office' => 'Kaliakair-1750',
            'latitude' => '24.0667000',
            'longitude' => '90.2167000',
            'directory_visible' => '1',
            'status' => 'active',
            'reason' => 'Physical contract verified and authorized by admin.',
        ];

        $res = $this->actingAs($admin)->post('/commands/accounts', $payload);
        $res->assertRedirect('/accounts');
        $res->assertSessionHas('success');

        $user = User::where('email', 'rafiq@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('dealer', $user->role);
        $this->assertSame('D-DHK-001', $user->dealer_code);
        $this->assertSame('Md. Rafiqul Islam', $user->name);
        $this->assertSame('01712345678', $user->phone);
        $this->assertTrue($user->directory_visible);
        $this->assertSame('19851234567890123', $user->nid); // Decrypted via cast
        $this->assertSame('মোঃ রফিকুল ইসলাম', $user->profile['name_bn']);
        $this->assertSame('Late Abdur Rahman', $user->profile['father_name']);
        $this->assertSame('Rahima Begum', $user->profile['mother_name']);
        $this->assertSame('Kaliakair', $user->profile['upazila']);
        $this->assertSame('Chapair', $user->profile['union']);
        $this->assertSame('Madina Care Point Gazipur', $user->profile['pharmacy']);

        // Check wallet initialized
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id]);

        // Check audit event
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'account.created',
            'subject' => (string) $user->id,
        ]);
    }

    public function test_staff_can_create_subdealer_and_link_active_sponsor(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sponsor = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'D-DHK-SPONSOR',
        ]);

        $payload = [
            'role' => 'sub_dealer',
            'dealer_code' => 'SD-CTG-001',
            'name' => 'Abul Kashem',
            'email' => 'kashem@example.com',
            'phone' => '01811223344',
            'division' => 'Chittagong',
            'district' => 'Cumilla',
            'sponsor_id' => $sponsor->id,
            'status' => 'active',
            'reason' => 'Sub-dealer assigned under Gazipur dealer.',
        ];

        $res = $this->actingAs($admin)->post('/commands/accounts', $payload);
        $res->assertRedirect('/accounts');

        $sub = User::where('email', 'kashem@example.com')->first();
        $this->assertNotNull($sub);
        $this->assertSame('sub_dealer', $sub->role);
        $this->assertSame('SD-CTG-001', $sub->dealer_code);
        $this->assertSame($sponsor->id, $sub->sponsor_id);
    }

    public function test_subdealer_cannot_be_published_in_public_directory(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $payload = [
            'role' => 'sub_dealer',
            'dealer_code' => 'SD-ERR-001',
            'name' => 'Invalid Sub',
            'email' => 'err@example.com',
            'directory_visible' => '1',
            'reason' => 'Test invalid sub-dealer publication',
        ];

        $res = $this->actingAs($admin)->post('/commands/accounts', $payload);
        $res->assertSessionHasErrors('directory_visible');
        $this->assertDatabaseMissing('users', ['email' => 'err@example.com']);
    }

    public function test_dealer_code_is_required_for_partners_and_must_be_unique(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->create([
            'role' => 'dealer',
            'dealer_code' => 'D-EXISTING',
            'email' => 'existing@example.com',
        ]);

        // Missing dealer_code
        $res = $this->actingAs($admin)->post('/commands/accounts', [
            'role' => 'dealer',
            'name' => 'No Code Dealer',
            'email' => 'nocode@example.com',
        ]);
        $res->assertSessionHasErrors('dealer_code');

        // Duplicate dealer_code
        $res = $this->actingAs($admin)->post('/commands/accounts', [
            'role' => 'dealer',
            'dealer_code' => 'D-EXISTING',
            'name' => 'Duplicate Code Dealer',
            'email' => 'dup@example.com',
        ]);
        $res->assertSessionHasErrors('dealer_code');
    }

    public function test_sponsor_must_be_active_dealer(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $suspendedDealer = User::factory()->create(['role' => 'dealer', 'status' => 'suspended']);

        $res = $this->actingAs($admin)->post('/commands/accounts', [
            'role' => 'sub_dealer',
            'dealer_code' => 'SD-SPONSOR-TEST',
            'name' => 'Sub Test',
            'email' => 'subtest@example.com',
            'sponsor_id' => $suspendedDealer->id,
        ]);
        $res->assertStatus(422);
    }

    public function test_staff_can_update_dealer_map_coordinates(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'latitude' => '23.8103',
            'longitude' => '90.4125',
            'reason' => 'Verified shop pin from field visit.',
        ])->assertRedirect()->assertSessionHas('success');

        $dealer->refresh();
        $this->assertEqualsWithDelta(23.8103, (float) $dealer->latitude, 0.0001);
        $this->assertEqualsWithDelta(90.4125, (float) $dealer->longitude, 0.0001);

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'latitude' => '',
            'longitude' => '',
            'reason' => 'Clear unverified map pin until survey completes.',
        ])->assertRedirect()->assertSessionHas('success');

        $dealer->refresh();
        $this->assertNull($dealer->latitude);
        $this->assertNull($dealer->longitude);
    }
}
