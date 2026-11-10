<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Support\SiteCustomization;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductSizeGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_office_ties_two_products_together_and_a_third_joins_them(): void
    {
        $owner = $this->owner();
        $small = $this->sized('Syrup X', '100 ml', '100.00');
        $medium = $this->sized('Syrup X', '300 ml', '250.00');
        $large = $this->sized('Syrup X', '500 ml', '400.00');

        $this->addSize($owner, $small, $medium)->assertSessionHasNoErrors()->assertRedirect();
        $groupId = $small->refresh()->product_group_id;

        $this->assertNotNull($groupId);
        $this->assertSame($groupId, $medium->refresh()->product_group_id);
        $this->assertDatabaseCount('product_groups', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.size_group_added', 'subject' => (string) $small->id]);

        $this->addSize($owner, $large, $small)->assertSessionHasNoErrors();

        $this->assertSame($groupId, $large->refresh()->product_group_id);
        $this->assertDatabaseCount('product_groups', 1);

        $this->actingAs($owner)->onPortal('sadmin')->get('/products/'.$medium->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('size_group.sizes.0.id', $small->id)
            ->where('size_group.sizes.1.id', $medium->id)
            ->where('size_group.sizes.1.current', true)
            ->where('size_group.sizes.2.label', '500 ml'));
    }

    public function test_grouping_changes_nothing_about_the_products_themselves(): void
    {
        $small = $this->sized('Syrup X', '100 ml', '100.00', ['dealer_price' => '70.00', 'stock' => 9, 'sku' => 'SYR-100']);
        $medium = $this->sized('Syrup X', '300 ml', '250.00', ['dealer_price' => '180.00', 'stock' => 4, 'sku' => 'SYR-300']);
        $before = fn (Product $product): array => $product->refresh()->only(['sku', 'mrp', 'dealer_price', 'sub_dealer_price', 'stock', 'active', 'registry_version']);
        $smallBefore = $before($small);
        $mediumBefore = $before($medium);

        $this->addSize($this->owner(), $small, $medium)->assertSessionHasNoErrors();

        $this->assertSame($smallBefore, $before($small));
        $this->assertSame($mediumBefore, $before($medium));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_taking_sizes_out_leaves_ordinary_products(): void
    {
        $owner = $this->owner();
        [$small, $medium, $large] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00'),
            $this->sized('Syrup X', '300 ml', '250.00'),
            $this->sized('Syrup X', '500 ml', '400.00'),
        );

        $this->removeSize($owner, $large)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($large->refresh()->product_group_id);
        $this->assertNotNull($small->refresh()->product_group_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.size_group_removed', 'subject' => (string) $large->id]);

        $this->removeSize($owner, $medium)->assertSessionHasNoErrors();

        $this->assertNull($medium->refresh()->product_group_id);
        $this->assertNull($small->refresh()->product_group_id, 'One size left is no longer a group.');
        $this->assertDatabaseCount('product_groups', 0);
    }

    public function test_deleting_a_product_closes_a_group_left_with_one_size(): void
    {
        [$small, $medium] = $this->group($this->sized('Syrup X', '100 ml', '100.00', ['stock' => 0]), $this->sized('Syrup X', '300 ml', '250.00'));

        $this->actingAs($this->owner())->onPortal('sadmin')
            ->delete('/commands/products/'.$small->id, ['reason' => 'Entered by mistake'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('products', ['id' => $small->id]);
        $this->assertNull($medium->refresh()->product_group_id);
        $this->assertDatabaseCount('product_groups', 0);
    }

    public function test_unsuitable_sizes_are_refused_in_plain_words(): void
    {
        $owner = $this->owner();
        $small = $this->sized('Syrup X', '100 ml', '100.00');
        $noWords = Product::factory()->create(['name' => 'Syrup Y']);
        $sameWords = $this->sized('Syrup X', '100ml', '120.00');
        $archived = $this->sized('Syrup X', '700 ml', '500.00', ['active' => false, 'archived_at' => now()]);
        [$grouped] = $this->group($this->sized('Tonic', '50 ml', '90.00'), $this->sized('Tonic', '75 ml', '110.00'));
        $otherGroupOne = $this->sized('Balm', '20 g', '60.00');
        $otherGroupTwo = $this->sized('Balm', '40 g', '95.00');
        $this->group($otherGroupOne, $otherGroupTwo);

        $this->addSize($owner, $small, $noWords)->assertSessionHasErrors(['with_product_id' => __('size_groups.err_no_size_written', ['name' => 'Syrup Y'])]);
        $this->addSize($owner, $small, $archived)->assertSessionHasErrors(['with_product_id' => __('size_groups.err_archived', ['name' => 'Syrup X'])]);
        $this->addSize($owner, $small, $sameWords)->assertSessionHasErrors(['with_product_id' => __('size_groups.err_size_repeated', ['size' => '100ml'])]);
        $this->addSize($owner, $small, $small)->assertSessionHasErrors('with_product_id');
        $this->addSize($owner, $grouped, $otherGroupOne)->assertSessionHasErrors(['with_product_id' => __('size_groups.err_in_another_group', ['name' => 'Balm'])]);

        $this->assertNull($small->refresh()->product_group_id);
        $this->assertDatabaseCount('product_groups', 2);
    }

    public function test_only_people_who_can_edit_the_catalog_can_change_sizes(): void
    {
        $small = $this->sized('Syrup X', '100 ml', '100.00');
        $medium = $this->sized('Syrup X', '300 ml', '250.00');
        [$groupedOne] = $this->group($this->sized('Tonic', '50 ml', '90.00'), $this->sized('Tonic', '75 ml', '110.00'));
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $inventoryStaff = User::factory()->staff('inventory')->create(['status' => 'active']);

        $this->addSize($dealer, $small, $medium)->assertForbidden();
        $this->addSize($inventoryStaff, $small, $medium)->assertForbidden();
        $this->removeSize($inventoryStaff, $groupedOne)->assertForbidden();
        $this->actingAs($inventoryStaff)->onPortal('sadmin')->getJson('/commands/products/'.$small->id.'/size-group/candidates?q=Syrup')->assertForbidden();

        $this->assertNull($small->refresh()->product_group_id);
        $this->assertNotNull($groupedOne->refresh()->product_group_id);
        $this->assertDatabaseMissing('audit_events', ['action' => 'product.size_group_added']);
    }

    public function test_size_search_offers_products_that_can_still_join(): void
    {
        $owner = $this->owner();
        [$grouped] = $this->group($this->sized('Syrup X', '100 ml', '100.00'), $this->sized('Syrup X', '300 ml', '250.00'));
        $free = $this->sized('Syrup X Large', '500 ml', '400.00');
        $this->sized('Syrup X Old', '50 ml', '50.00', ['active' => false, 'archived_at' => now()]);

        $items = $this->actingAs($owner)->onPortal('sadmin')
            ->getJson('/commands/products/'.$grouped->id.'/size-group/candidates?q=Syrup')
            ->assertOk()
            ->json('items');

        $this->assertSame([$free->id], array_column($items, 'id'));
    }

    public function test_public_list_shows_one_card_with_the_right_sizes_and_each_size_keeps_its_address(): void
    {
        [$small, $medium, $large] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00'),
            $this->sized('Syrup X', '300 ml', '250.00'),
            $this->sized('Syrup X', '500 ml', '400.00'),
        );
        $plain = Product::factory()->create(['name' => 'Zinc Plain']);

        $response = $this->get('/products')->assertOk();

        $cards = $this->cards($response);
        $this->assertSame(2, $cards->total());
        $this->assertSame([$small->id, $plain->id], array_column($cards->items(), 'id'));
        $this->assertSame([$small->id, $medium->id, $large->id], array_column($cards->items()[0]['sizes'], 'id'));
        $this->assertArrayNotHasKey('sizes', $cards->items()[1]);
        $response->assertSee('data-size-button', false)->assertSee('300 ml')->assertSee('/products/'.$large->id, false);
        $this->assertSame(3, substr_count((string) $response->getContent(), 'data-size-button'));

        $this->get('/products/'.$medium->id)->assertOk()->assertSee('Syrup X')->assertSee('/products/'.$large->id, false);
        $this->get('/products/'.$plain->id)->assertOk()->assertDontSee('ref-detail-sizes', false);
    }

    public function test_archived_and_draft_sizes_do_not_appear_and_one_left_looks_ordinary(): void
    {
        [$small, $medium, $large] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00'),
            $this->sized('Syrup X', '300 ml', '250.00'),
            $this->sized('Syrup X', '500 ml', '400.00'),
        );
        $medium->update(['active' => false, 'archived_at' => now()]);

        $twoLeft = $this->get('/products')->assertOk()->assertDontSee('300 ml');
        $this->assertSame([$small->id, $large->id], array_column($this->cards($twoLeft)->items()[0]['sizes'], 'id'));
        $this->get('/products/'.$medium->id)->assertNotFound();
        $this->get('/products/'.$small->id)->assertOk()->assertDontSee('/products/'.$medium->id, false);

        $large->update(['active' => false]);

        $oneLeft = $this->get('/products')->assertOk()->assertDontSee('data-size-button', false);
        $this->assertSame([], $this->cards($oneLeft)->items()[0]['sizes']);
        $this->assertSame($small->id, $this->cards($oneLeft)->items()[0]['id']);
        $this->get('/products/'.$small->id)->assertOk()->assertDontSee('ref-detail-sizes', false);
    }

    public function test_search_by_any_sizes_code_or_name_finds_the_group_on_that_size(): void
    {
        [$small, $medium] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00', ['sku' => 'SYR-100']),
            $this->sized('Syrup X Family', '300 ml', '250.00', ['sku' => 'SYR-300']),
        );
        $plain = Product::factory()->create(['name' => 'Zinc Plain', 'sku' => 'ZINC-1']);

        $bySku = $this->cards($this->get('/products?q=SYR-300')->assertOk());
        $this->assertSame([$medium->id], array_column($bySku->items(), 'id'));
        $this->assertSame([$small->id, $medium->id], array_column($bySku->items()[0]['sizes'], 'id'));

        $byName = $this->cards($this->get('/products?q=Family')->assertOk());
        $this->assertSame([$medium->id], array_column($byName->items(), 'id'));

        $this->assertSame(0, $this->cards($this->get('/products?q=ZINC-1')->assertOk())->total(), 'A product with no group searches as it always did.');
        $this->assertSame([$plain->id], array_column($this->cards($this->get('/products?q=Zinc')->assertOk())->items(), 'id'));

        $found = $this->getJson('/search/products?q=SYR')->assertOk()->json('items');
        $this->assertCount(1, $found);
    }

    public function test_filters_and_paging_count_groups_with_no_gaps_or_repeats(): void
    {
        foreach (range(1, 12) as $index) {
            Product::factory()->create(['name' => sprintf('Plain %02d', $index)]);
        }
        [$firstGroup] = $this->group($this->sized('Alpha Syrup', '100 ml', '100.00'), $this->sized('Alpha Syrup', '300 ml', '250.00'), $this->sized('Alpha Syrup', '500 ml', '400.00'));
        [$secondGroup] = $this->group($this->sized('Plain 05 Syrup', '100 ml', '100.00'), $this->sized('Plain 05 Syrup', '300 ml', '250.00'));

        $pageOne = $this->cards($this->get('/products')->assertOk());
        $pageTwo = $this->cards($this->get('/products?page=2')->assertOk());
        $shown = [...array_column($pageOne->items(), 'id'), ...array_column($pageTwo->items(), 'id')];

        $this->assertSame(14, $pageOne->total());
        $this->assertSame(2, $pageOne->lastPage());
        $this->assertCount(12, $pageOne->items());
        $this->assertCount(2, $pageTwo->items());
        $this->assertCount(14, array_unique($shown));
        $this->assertContains($firstGroup->id, $shown);
        $this->assertContains($secondGroup->id, $shown);
    }

    public function test_a_filter_keeps_the_card_when_any_size_matches_and_opens_on_that_size(): void
    {
        [$small, $medium, $large] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00', ['stock' => 0]),
            $this->sized('Syrup X', '300 ml', '250.00', ['stock' => 7]),
            $this->sized('Syrup X', '500 ml', '400.00', ['stock' => 0]),
        );

        $inStock = $this->cards($this->get('/products?in_stock=1')->assertOk());
        $this->assertSame(1, $inStock->total());
        $this->assertSame($medium->id, $inStock->items()[0]['id']);
        $this->assertSame([$small->id, $medium->id, $large->id], array_column($inStock->items()[0]['sizes'], 'id'));

        $priced = $this->cards($this->get('/products?min_price=390&max_price=410')->assertOk());
        $this->assertSame(1, $priced->total());
        $this->assertSame($large->id, $priced->items()[0]['id']);

        $this->assertSame(0, $this->cards($this->get('/products?min_price=900')->assertOk())->total());

        $noFilter = $this->cards($this->get('/products')->assertOk());
        $this->assertSame($medium->id, $noFilter->items()[0]['id'], 'A card opens on a size that can be bought.');
    }

    public function test_home_page_shows_one_card_for_a_group_where_it_was_first_chosen(): void
    {
        $plain = Product::factory()->create(['name' => 'Plain Tonic', 'featured' => true, 'featured_position' => 1]);
        [$small, $medium, $large] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00'),
            $this->sized('Syrup X', '300 ml', '250.00', ['featured' => true, 'featured_position' => 2]),
            $this->sized('Syrup X', '500 ml', '400.00', ['featured' => true, 'featured_position' => 3]),
        );
        SiteCustomization::forgetCache();

        $cards = SiteCustomization::getFeaturedProducts();

        $this->assertSame([$plain->id, $medium->id], array_column($cards, 'id'));
        $this->assertSame([$small->id, $medium->id, $large->id], array_column($cards[1]['sizes'], 'id'));
        $this->get('/')->assertOk()->assertSee('data-size-button', false);
    }

    public function test_size_words_follow_the_visitors_language_on_the_cached_home_page(): void
    {
        $this->group(
            $this->sized('Syrup X', '100 ml', '100.00', ['featured' => true, 'featured_position' => 1, 'pack_size_bn' => '১০০ মিলি']),
            $this->sized('Syrup X', '300 ml', '250.00', ['pack_size_bn' => '৩০০ মিলি']),
        );
        SiteCustomization::forgetCache();

        $card = SiteCustomization::getFeaturedProducts()[0];

        $this->assertSame(['bn' => '১০০ মিলি', 'en' => '100 ml'], $card['sizes'][0]['labels']);
    }

    public function test_an_order_for_a_chosen_size_moves_only_that_size_and_posts_commission_as_a_normal_product(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $staff = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        [$small, $medium] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00', ['dealer_price' => '50.00', 'stock' => 8]),
            $this->sized('Syrup X', '300 ml', '200.00', ['dealer_price' => '100.00', 'stock' => 5]),
        );

        $orderId = $this->actingAs($dealer)->onPortal('dealer')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $medium->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank',
            'reference' => 'SIZE-RECEIPT-1',
            'amount' => '100.00',
            'proof' => UploadedFile::fake()->image('payment-proof.jpg'),
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('order_lines', ['order_id' => $orderId, 'product_id' => $medium->id, 'unit_price' => '100.00']);
        $this->assertDatabaseMissing('order_lines', ['order_id' => $orderId, 'product_id' => $small->id]);

        $claim = DB::table('payment_claims')->where('order_id', $orderId)->value('id');
        $this->actingAs($staff)->onPortal('manage')->postJson('/api/v1/claims/'.$claim.'/verify', ['approve' => true, 'reason' => 'Matched bank statement'])->assertOk();
        $this->onPortal('manage')->postJson('/api/v1/orders/'.$orderId.'/decision', ['status' => 'approved', 'reason' => 'Payment confirmed'])->assertOk();
        $line = DB::table('order_lines')->where('order_id', $orderId)->value('id');
        $this->onPortal('manage')->postJson('/api/v1/orders/'.$orderId.'/dispatch', [
            'courier' => 'Portal courier',
            'tracking' => 'SIZE-TRACK-1',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $line, 'quantity' => 1]],
        ])->assertOk();
        $shipment = DB::table('shipments')->where('order_id', $orderId)->value('id');
        $this->onPortal('manage')->postJson('/api/v1/orders/'.$orderId.'/shipments/'.$shipment.'/delivered', ['reason' => 'Proof of delivery checked'])->assertOk();

        $this->assertDatabaseHas('products', ['id' => $medium->id, 'stock' => 4]);
        $this->assertDatabaseHas('products', ['id' => $small->id, 'stock' => 8]);
        $this->assertDatabaseHas('commission_batches', ['order_id' => $orderId, 'cash_basis' => '100.00', 'company_share' => '60.00', 'total_allocated' => '40.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '5.00']);
    }

    public function test_a_stop_on_one_size_does_not_touch_the_other_size(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        [$small, $medium] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00', ['stock' => 50]),
            $this->sized('Syrup X', '300 ml', '200.00', ['stock' => 20, 'is_stock_paused' => true]),
        );
        $order = fn (Product $product) => $this->actingAs($dealer)->onPortal('dealer')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Office',
        ]);

        $order($medium)->assertUnprocessable();
        $order($small)->assertCreated();

        $this->assertDatabaseMissing('order_lines', ['product_id' => $medium->id]);
    }

    public function test_partners_get_each_size_at_its_own_price_and_never_an_unpublished_size(): void
    {
        [$small, $medium, $draft] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00', ['dealer_price' => '60.00', 'sub_dealer_price' => '75.00', 'sku' => 'SYR-100']),
            $this->sized('Syrup X', '300 ml', '250.00', ['dealer_price' => '150.00', 'sub_dealer_price' => '185.00', 'sku' => 'SYR-300']),
            $this->sized('Syrup Hidden', '900 ml', '600.00', ['active' => false, 'sku' => 'SYR-SECRET-900']),
        );
        $archived = $this->sized('Syrup Gone', '700 ml', '500.00', ['active' => false, 'archived_at' => now(), 'sku' => 'SYR-SECRET-700']);
        $archived->update(['product_group_id' => $small->product_group_id]);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $subDealer = User::factory()->subDealer()->create(['status' => 'active']);

        foreach ([['dealer', $dealer, '60.00', '150.00'], ['subdealer', $subDealer, '75.00', '185.00']] as [$portal, $partner, $smallPrice, $mediumPrice]) {
            foreach (['/purchase', '/catalog'] as $path) {
                $response = $this->actingAs($partner)->onPortal($portal)->get($path)->assertOk();
                $response->assertInertia(fn (Assert $page) => $page
                    ->has('data.products', 2)
                    ->where('data.products.0.id', $small->id)
                    ->where('data.products.0.price', $smallPrice)
                    ->where('data.products.0.size_label', '100 ml')
                    ->where('data.products.0.size_position', 0)
                    ->where('data.products.1.id', $medium->id)
                    ->where('data.products.1.price', $mediumPrice)
                    ->where('data.products.1.size_position', 1)
                    ->where('data.products.1.size_group_id', (int) $small->product_group_id));
                $this->assertStringNotContainsString('SYR-SECRET', (string) $response->getContent());
                $this->assertStringNotContainsString('900 ml', (string) $response->getContent());
                $this->assertNotContains($draft->id, array_column($response->viewData('page')['props']['data']['products'], 'id'));
            }
        }
    }

    public function test_office_list_shows_the_size_label_only_on_grouped_products(): void
    {
        $owner = $this->owner();
        [$small] = $this->group($this->sized('Syrup X', '100 ml', '100.00'), $this->sized('Syrup X', '300 ml', '250.00'));
        $plain = $this->sized('Zinc Plain', '30 tablets', '90.00');

        $this->actingAs($owner)->onPortal('sadmin')->get('/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.products', function ($products) use ($small, $plain): bool {
                $rows = collect($products)->keyBy('id');

                return $rows[$small->id]['size_label'] === '100 ml' && ! isset($rows[$plain->id]['size_label']);
            }));
    }

    public function test_both_languages_have_the_same_size_words(): void
    {
        $english = require lang_path('en/size_groups.php');
        $bangla = require lang_path('bn/size_groups.php');

        $this->assertSame(array_keys($english), array_keys($bangla));
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function sized(string $name, string $label, string $mrp, array $attributes = []): Product
    {
        return Product::factory()->create(['name' => $name, 'pack_size_en' => $label, 'mrp' => $mrp, 'dealer_price' => bcmul($mrp, '0.7', 2), ...$attributes]);
    }

    /** @return list<Product> */
    private function group(Product ...$sizes): array
    {
        $group = ProductGroup::factory()->create();
        foreach ($sizes as $size) {
            $size->update(['product_group_id' => $group->id]);
        }

        return array_values($sizes);
    }

    private function addSize(User $actor, Product $product, Product $other): TestResponse
    {
        return $this->actingAs($actor)->onPortal($actor->staff() ? 'sadmin' : 'dealer')->from('/products/'.$product->id)
            ->post('/commands/products/'.$product->id.'/size-group', ['with_product_id' => $other->id]);
    }

    private function removeSize(User $actor, Product $product): TestResponse
    {
        return $this->actingAs($actor)->onPortal('sadmin')->from('/products/'.$product->id)->delete('/commands/products/'.$product->id.'/size-group');
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    private function cards(TestResponse $response): LengthAwarePaginator
    {
        return $response->viewData('catalog');
    }
}
