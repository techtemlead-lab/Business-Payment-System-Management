<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AddPartnerPageSponsorListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        User::factory()->dealer()->count(3)->create();
    }

    public function test_the_add_dealer_form_does_not_download_every_dealer(): void
    {
        $this->actingAs(User::factory()->staff('account_manager')->create())->onPortal('manage')->get('/accounts/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('AccountCreate')->has('sponsors', 0));
    }

    public function test_the_add_staff_form_still_lists_sponsors(): void
    {
        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/accounts/create?intent=staff')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sponsors', 3));
    }
}
