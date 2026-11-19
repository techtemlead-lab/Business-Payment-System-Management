<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** R4 (decision D8): a sub-dealer price saved rounded up to a whole taka is put back to exactly 75% of MRP. */
class SubDealerPriceExactCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_rounded_up_prices_are_corrected_and_nothing_is_written_without_apply(): void
    {
        $roundedUp = Product::factory()->create(['mrp' => '90.00', 'sub_dealer_price' => '68.00']);
        $onPurpose = Product::factory()->create(['mrp' => '100.00', 'sub_dealer_price' => '80.00']);
        $exact = Product::factory()->create(['mrp' => '90.00', 'sub_dealer_price' => '67.50']);
        $none = Product::factory()->create(['mrp' => '90.00', 'sub_dealer_price' => null]);

        $this->artisan('madina:fix-sub-dealer-prices')->expectsOutputToContain('would change')->assertSuccessful();
        $this->assertSame('68.00', $roundedUp->fresh()->sub_dealer_price);

        $this->artisan('madina:fix-sub-dealer-prices', ['--apply' => true])->assertSuccessful();
        $this->assertSame('67.50', $roundedUp->fresh()->sub_dealer_price);
        $this->assertSame('80.00', $onPurpose->fresh()->sub_dealer_price);
        $this->assertSame('67.50', $exact->fresh()->sub_dealer_price);
        $this->assertNull($none->fresh()->sub_dealer_price);
        $this->assertDatabaseHas('audit_events', ['action' => 'pricing.sub_dealer_price_exact']);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        Product::factory()->create(['mrp' => '390.00', 'sub_dealer_price' => '293.00']);

        $this->artisan('madina:fix-sub-dealer-prices', ['--apply' => true])->assertSuccessful();
        $this->artisan('madina:fix-sub-dealer-prices', ['--apply' => true])->expectsOutputToContain('Nothing to change')->assertSuccessful();
        $this->assertSame('292.50', Product::query()->first()->sub_dealer_price);
    }
}
