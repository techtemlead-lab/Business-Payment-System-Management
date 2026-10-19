<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_home_is_reachable(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_inactive_users_are_blocked_from_api(): void
    {
        $user = User::factory()->create(['role' => 'dealer', 'status' => 'suspended']);
        $this->actingAs($user)->getJson('/api/v1/me')->assertForbidden();
    }
}
