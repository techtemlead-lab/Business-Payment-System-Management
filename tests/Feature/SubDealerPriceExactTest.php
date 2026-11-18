<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** MA-22: a product without a sub-dealer price gets exactly 75% of MRP, to the paisa. */
class SubDealerPriceExactTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_sub_dealer_price_is_seventy_five_percent_of_mrp_without_rounding_up(): void
    {
        $product = Product::factory()->create(['mrp' => '90.00', 'dealer_price' => '54.00', 'sub_dealer_price' => null]);
        $owner = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($owner)->patch('/commands/products/'.$product->id, [
            'version' => (string) $product->registry_version, 'mrp' => '90.00', 'reason' => 'Set the price list',
        ])->assertSessionHasNoErrors();

        $this->assertSame('67.50', $product->fresh()->sub_dealer_price);
    }

    public function test_a_price_typed_by_the_owner_is_kept(): void
    {
        $product = Product::factory()->create(['mrp' => '90.00', 'dealer_price' => '54.00', 'sub_dealer_price' => null]);
        $owner = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($owner)->patch('/commands/products/'.$product->id, [
            'version' => (string) $product->registry_version, 'sub_dealer_price' => '66.00', 'reason' => 'Special price',
        ])->assertSessionHasNoErrors();

        $this->assertSame('66.00', $product->fresh()->sub_dealer_price);
    }
}
