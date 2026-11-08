<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPriceKeptOnSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** The product form always sends the exact price together with a rounded percent. */
    public function test_saving_keeps_the_prices_typed_even_when_the_percent_is_rounded(): void
    {
        $product = Product::factory()->create(['mrp' => '1000.00', 'dealer_price' => '733.33', 'sub_dealer_price' => '755.55'])->refresh();

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->patch('/commands/products/'.$product->id, [
                'version' => (string) $product->registry_version,
                'mrp' => '1000.00',
                'dealer_price' => '733.33',
                'dealer_discount_pct' => '27',
                'sub_dealer_price' => '755.55',
                'sub_dealer_discount_pct' => '24',
                'description' => 'Only the description changed',
                'reason' => 'Editing the description only',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $product->refresh();
        $this->assertSame('733.33', (string) $product->dealer_price);
        $this->assertSame('755.55', (string) $product->sub_dealer_price);
        $this->assertSame('Only the description changed', $product->description);
    }

    public function test_a_percent_on_its_own_still_sets_the_price(): void
    {
        $product = Product::factory()->create(['mrp' => '1000.00', 'dealer_price' => '700.00', 'sub_dealer_price' => '750.00'])->refresh();

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->patch('/commands/products/'.$product->id, [
                'version' => (string) $product->registry_version,
                'dealer_discount_pct' => '40',
                'sub_dealer_discount_pct' => '25',
                'reason' => 'Setting prices from percent',
            ])
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('600.00', (string) $product->dealer_price);
        $this->assertSame('750.00', (string) $product->sub_dealer_price);
    }

    public function test_a_dealer_price_above_eighty_percent_of_mrp_names_the_rule_that_was_broken(): void
    {
        $product = Product::factory()->create(['mrp' => '1000.00', 'dealer_price' => '700.00'])->refresh();

        $response = $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->patch('/commands/products/'.$product->id, [
                'version' => (string) $product->registry_version,
                'dealer_price' => '900.00',
                'reason' => 'Trying a price too close to MRP',
            ]);

        $response->assertSessionHasErrors('dealer_price', __('portal.err_dealer_discount_too_small'));
        $response->assertSessionDoesntHaveErrors('dealer_discount_pct');
        $this->assertSame('700.00', (string) $product->refresh()->dealer_price);
    }

    public function test_a_sub_dealer_price_above_eighty_percent_of_mrp_names_the_rule_that_was_broken(): void
    {
        $product = Product::factory()->create(['mrp' => '1000.00', 'dealer_price' => '700.00', 'sub_dealer_price' => '750.00'])->refresh();

        $response = $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->patch('/commands/products/'.$product->id, [
                'version' => (string) $product->registry_version,
                'sub_dealer_price' => '950.00',
                'reason' => 'Trying a sub-dealer price too close to MRP',
            ]);

        $response->assertSessionHasErrors('sub_dealer_price', __('portal.err_sub_dealer_discount_too_small'));
        $response->assertSessionDoesntHaveErrors('sub_dealer_discount_pct');
    }

    public function test_the_owner_going_past_the_top_limit_is_told_the_owner_limit(): void
    {
        $product = Product::factory()->create(['mrp' => '1000.00', 'dealer_price' => '700.00'])->refresh();

        $response = $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->patch('/commands/products/'.$product->id, [
                'version' => (string) $product->registry_version,
                'dealer_price' => '250.00',
                'reason' => 'Trying a seventy-five percent discount',
            ]);

        $response->assertSessionHasErrors('dealer_price', __('portal.err_dealer_discount_limit_owner'));
        $this->assertSame('700.00', (string) $product->refresh()->dealer_price);
    }
}
