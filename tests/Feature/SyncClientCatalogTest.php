<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncClientCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_upserts_client_skus_images_and_company_defaults(): void
    {
        Storage::fake('local');
        Product::factory()->create(['sku' => 'OLD-SKU', 'name' => 'Legacy product', 'active' => true]);

        $this->artisan('madina:sync-client-catalog', ['--deactivate-others' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('products', [
            'sku' => 'MNHC-NAT-500',
            'name' => 'Naturalife Fruit Syrup 500ml',
            'mrp' => '400.00',
            'dealer_price' => '200.00',
            'active' => true,
        ]);
        $this->assertDatabaseHas('products', [
            'sku' => 'MNHC-TSK-60',
            'mrp' => '450.00',
            'dealer_price' => '270.00',
        ]);
        $this->assertSame(10, Product::query()->where('active', true)->where('sku', 'like', 'MNHC-%')->count());
        $this->assertDatabaseHas('products', ['sku' => 'OLD-SKU', 'active' => false]);

        $naturalife = Product::query()->where('sku', 'MNHC-NAT-500')->firstOrFail();
        $this->assertNotEmpty($naturalife->image_path);
        Storage::disk('local')->assertExists($naturalife->image_path);

        $custom = SiteCustomization::all();
        $this->assertSame('holisticnutracare@gmail.com', $custom['contact_email']);
        $this->assertSame('+8801730532966', $custom['contact_phone']);
        $this->assertStringContainsString('2026-00563', $custom['footer_about_desc']);
        $this->assertStringContainsString('Pirganj', $custom['office_address']);
    }

    public function test_dry_run_does_not_write_products_or_customization(): void
    {
        Storage::fake('local');
        SiteCustomization::save(['contact_email' => 'keep@test.local']);

        $this->artisan('madina:sync-client-catalog', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('products', 0);
        $this->assertSame('keep@test.local', SiteCustomization::all()['contact_email']);
    }
}
