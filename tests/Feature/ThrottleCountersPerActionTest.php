<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThrottleCountersPerActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_other_requests_do_not_use_up_the_download_limit(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        foreach (range(1, 6) as $request) {
            $this->actingAs($owner)->onPortal('sadmin')->getJson('/commands/place-codes/children?level=union')->assertOk();
        }

        $this->actingAs($owner)->onPortal('sadmin')->get('/exports/accounts?section=accounts')->assertOk();
    }

    public function test_one_action_still_stops_at_its_own_limit(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        foreach (range(1, 5) as $request) {
            $this->actingAs($owner)->onPortal('sadmin')->get('/exports/accounts?section=accounts')->assertOk();
        }

        $this->actingAs($owner)->onPortal('sadmin')->get('/exports/accounts?section=accounts')->assertTooManyRequests();
    }
}
