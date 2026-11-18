<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The order screen searches one list of everything the partner may order, so the server must send
 * the whole range with this partner's price, and nothing the partner may not see: the cost price and,
 * by the owner's rule (PR 426), any stock figure or stock state.
 */
class PartnerOrderSearchDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_dealer_gets_every_active_product_with_his_own_price_and_no_stock_facts(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        Product::factory()->create(['name' => 'In Stock Tonic', 'category' => 'Tonic', 'dealer_price' => '140.00', 'mrp' => '200.00', 'stock' => 12]);
        Product::factory()->create(['name' => 'Paused Oil', 'category' => 'Oil', 'stock' => 12, 'is_stock_paused' => true]);
        Product::factory()->create(['name' => 'Empty Syrup', 'category' => 'Syrup', 'stock' => 0]);
        Product::factory()->create(['name' => 'Hidden Old Product', 'active' => false]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.products', 3)
                ->where('data.products_truncated', false)
                ->where('data.products', function ($products) {
                    $byName = collect($products)->keyBy('name');

                    return $byName->has(['In Stock Tonic', 'Paused Oil', 'Empty Syrup'])
                        && ! $byName->has('Hidden Old Product')
                        && (float) $byName['In Stock Tonic']['price'] === 140.0
                        && (float) $byName['In Stock Tonic']['mrp'] === 200.0
                        && ! array_key_exists('cost_price', $byName['In Stock Tonic'])
                        && $byName->every(fn (array $product): bool => ! array_key_exists('stock', $product)
                            && ! array_key_exists('low_stock_at', $product)
                            && ! array_key_exists('stock_status', $product)
                            && ! array_key_exists('is_stock_paused', $product));
                }));
    }

    public function test_the_whole_range_reaches_the_search_not_just_the_first_page(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        Product::factory()->count(230)->create();

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.products', 230)
                ->where('data.products_truncated', false));
    }

    public function test_a_sub_dealer_is_sent_the_price_of_a_sub_dealer(): void
    {
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);
        Product::factory()->create(['name' => 'Tier Tonic', 'mrp' => '200.00', 'dealer_price' => '140.00', 'sub_dealer_price' => '150.00']);

        $this->actingAs($subDealer)->onPortal('subdealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.products.0.name', 'Tier Tonic')
                ->where('data.products.0.price', fn ($price) => (float) $price === 150.0));
    }
}
