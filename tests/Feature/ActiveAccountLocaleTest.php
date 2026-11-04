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
                'email' => __('auth_ui.account_unavailable_contact', [], 'bn'),
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
            ->assertJsonFragment(['message' => __('auth_ui.account_unavailable', [], 'bn')]);
    }
}
