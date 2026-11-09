<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginIntendedRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_portal_section_is_not_found_without_remembering_it_for_login(): void
    {
        $this->onPortal('dealer')->get('/dealers')->assertNotFound();

        $this->assertNull(session('url.intended'));
    }

    public function test_known_portal_section_still_requires_login(): void
    {
        $this->onPortal('dealer')->get('/orders')->assertRedirect();
    }

    public function test_staff_alias_sections_still_resolve(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/customization')->assertOk();
        $this->actingAs($owner)->onPortal('sadmin')->get('/capital-flow')->assertRedirect('/treasury/funds');
        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury/funds')->assertOk();
    }
}
