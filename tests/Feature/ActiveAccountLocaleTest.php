<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveAccountLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_partner_sees_bangla_unavailable_message(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'suspended',
            'locale' => 'bn',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertRedirect()
            ->assertSessionHasErrors([
                'email' => 'এই অ্যাকাউন্ট উপলব্ধ নয়। কোম্পানির সাথে যোগাযোগ করুন।',
            ]);
    }

    public function test_inactive_partner_json_returns_localized_forbidden_message(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'suspended',
            'locale' => 'bn',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'এই অ্যাকাউন্ট উপলব্ধ নয়।']);
    }
}
