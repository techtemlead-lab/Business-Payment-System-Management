<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The partner products page behind the shopping cart: what the cards are given, and that the switch still works. */
class PartnerCatalogCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_products_page_gives_each_size_family_its_group_label_position_and_partner_price(): void
    {
        $group = ProductGroup::factory()->create();
        $small = $this->product('Syrup X', '100 ml', '100.00', '60.00', '75.00', $group);
        $large = $this->product('Syrup X', '300 ml', '250.00', '150.00', '187.50', $group);
        $single = $this->product('Zinc Plain', '30 tablets', '90.00', '55.00', '67.50');

        foreach ([['dealer', 'dealer', ['60.00', '150.00', '55.00']], ['subdealer', 'sub_dealer', ['75.00', '187.50', '67.50']]] as [$portal, $role, $prices]) {
            $partner = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($partner)->onPortal($portal)->get('/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('data.products', function ($products) use ($small, $large, $single, $group, $prices): bool {
                    $rows = collect($products)->keyBy('id');

                    return $rows[$small->id]['size_group_id'] === (int) $group->id
                        && $rows[$large->id]['size_group_id'] === (int) $group->id
                        && $rows[$small->id]['size_position'] < $rows[$large->id]['size_position']
                        && $rows[$small->id]['size_label'] === '100 ml'
                        && $rows[$large->id]['size_label'] === '300 ml'
                        && ! isset($rows[$single->id]['size_group_id'])
                        && [$rows[$small->id]['price'], $rows[$large->id]['price'], $rows[$single->id]['price']] === $prices
                        && ! isset($rows[$small->id]['stock'], $rows[$small->id]['dealer_price'], $rows[$small->id]['cost_price']);
                }));
        }
    }

    public function test_the_sub_dealer_price_is_exactly_the_stored_seventy_five_percent_of_the_mrp(): void
    {
        $odd = $this->product('Odd Price', '1 pack', '90.00', '55.00', '67.50');
        $subDealer = User::factory()->subDealer()->create(['status' => 'active']);

        $this->actingAs($subDealer)->onPortal('subdealer')->get('/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.products.0.id', $odd->id)
            ->where('data.products.0.mrp', '90.00')
            ->where('data.products.0.price', '67.50'));
    }

    public function test_the_products_page_says_how_many_can_be_promised_now_and_the_order_page_still_does(): void
    {
        $product = $this->product('Stocked', '1 pack', '100.00', '60.00', '75.00');
        $product->update(['stock' => 5]);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        foreach (['/catalog', '/purchase'] as $path) {
            $this->actingAs($dealer)->onPortal('dealer')->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where("data.available_now.{$product->id}", fn ($free): bool => (int) $free === 5));
        }
    }

    public function test_the_page_stays_off_for_partners_while_the_switch_is_off_and_never_opens_for_the_public(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        config(['madina.partner_catalog_enabled' => false]);
        $this->actingAs($dealer)->onPortal('dealer')->get('/catalog')->assertRedirect('/');
        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk();

        config(['madina.partner_catalog_enabled' => true]);
        $this->actingAs($dealer)->onPortal('dealer')->get('/catalog')->assertOk();
        auth()->logout();
        $this->onPortal('dealer')->get('/catalog')->assertRedirect();
    }

    private function product(string $name, string $label, string $mrp, string $dealerPrice, string $subDealerPrice, ?ProductGroup $group = null): Product
    {
        return Product::factory()->create([
            'name' => $name, 'pack_size_en' => $label, 'mrp' => $mrp, 'dealer_price' => $dealerPrice,
            'sub_dealer_price' => $subDealerPrice, 'active' => true, 'product_group_id' => $group?->id,
        ]);
    }
}
