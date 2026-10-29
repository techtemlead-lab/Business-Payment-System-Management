<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerPurchasePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_dealer_purchase_page_renders_matrix_props(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('section', 'purchase')
                ->has('data.products')
                ->has('collectionAccounts'));
    }

    public function test_subdealer_purchase_page_renders(): void
    {
        $subdealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        $this->actingAs($subdealer)->onPortal('subdealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/subdealer/Dashboard')
                ->where('section', 'purchase')
                ->has('data.products'));
    }
}
