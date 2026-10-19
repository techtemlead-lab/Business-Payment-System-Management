<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LiveCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_local_website_never_substitutes_a_sample_catalogue(): void
    {
        $this->withoutVite();
        $this->app->detectEnvironment(fn () => 'local');
        config(['madina.preview_mode' => false]);

        $this->assertSame([], SiteCustomization::getFeaturedProducts());
        $this->get('/')->assertOk()->assertSee('Our collection is being prepared.')
            ->assertDontSee('/products/preview/', false)->assertDontSee('Client preview');
        $this->get('/products')->assertOk()->assertSee('0 Products Found');
        $this->get('/products/preview/reference-product-01')->assertNotFound();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_company_published_products_replace_the_empty_state_while_drafts_remain_private(): void
    {
        $this->withoutVite();
        $published = Product::factory()->create(['name' => 'Published company product', 'active' => true]);
        Product::factory()->create(['name' => 'Unpublished company product', 'active' => false]);

        foreach (['/', '/products'] as $path) {
            $this->get($path)->assertOk()->assertSee($published->name)->assertDontSee('Unpublished company product');
        }
    }

    public function test_featured_public_card_does_not_return_partner_or_campaign_prices(): void
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        SiteCustomization::save(['campaign_cards' => [['type' => 'single', 'product_id' => $product->id, 'price' => '75.00', 'tag' => 'Sale']]]);

        $card = SiteCustomization::formatPublicProductCard($product);

        $this->assertSame('200.00', $card['price']);
        $this->assertSame('200.00', $card['mrp']);
        $this->assertSame(0, $card['discount_pct']);
        $this->assertSame('', $card['savings_text']);
        $this->assertNull($card['campaign']);
    }

    public function test_demo_provisioning_cannot_repopulate_a_clean_local_business_database(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['madina.preview_mode' => false]);

        $this->artisan('madina:demo')->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_demo_provisioning_creates_accounts_when_local_preview_mode_is_enabled(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['madina.preview_mode' => true]);

        $this->artisan('madina:demo')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@madina.test', 'role' => 'super_admin']);
        $this->assertDatabaseHas('users', ['email' => 'dealer@madina.test', 'role' => 'dealer']);
        $this->assertDatabaseHas('users', ['email' => 'subdealer@madina.test', 'role' => 'sub_dealer']);
        $this->assertDatabaseCount('products', 4);
        $this->assertGreaterThanOrEqual(1, Order::query()->count());
        $this->assertDatabaseCount('payment_accounts', 2);
        $this->assertFileExists(base_path('.local/demo-access.json'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_owner_dashboard_labels_demonstration_data_only_when_explicitly_enabled(bool $previewMode): void
    {
        $this->withoutVite();
        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->app->detectEnvironment(fn () => 'local');
        config(['madina.preview_mode' => $previewMode]);

        $this->actingAs($owner)->get('/company')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('demoMode', $previewMode));
    }
}
