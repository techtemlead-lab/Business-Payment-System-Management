<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_product_page_requires_active_catalog_item(): void
    {
        $product = Product::factory()->create(['active' => true, 'name' => 'Commerce Tonic', 'sku' => 'COM-1']);
        $this->get('/products/'.$product->id)->assertOk()->assertSee('Commerce Tonic');

        $product->update(['active' => false]);
        $this->get('/products/'.$product->id)->assertNotFound();
    }

    public function test_dealer_can_open_catalog_workspace(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'dealer_code' => 'D-'.Str::upper(Str::random(6))]);
        $this->actingAs($dealer)->onPortal('dealer')->get('/catalog')->assertOk();
    }
}
