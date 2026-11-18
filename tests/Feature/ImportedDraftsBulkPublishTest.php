<?php

namespace Tests\Feature;

use App\Http\Controllers\ProductRegistryController;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Imported products arrive as drafts; the catalogue editor puts the chosen ones on sale together.
 */
class ImportedDraftsBulkPublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_chosen_drafts_go_on_sale_and_unusable_or_archived_ones_stay(): void
    {
        $editor = User::factory()->staff('super_admin')->create();
        $ready = Product::factory()->create(['active' => false, 'archived_at' => null, 'mrp' => '200.00', 'dealer_price' => '150.00']);
        $noDealerPrice = Product::factory()->create(['active' => false, 'archived_at' => null, 'mrp' => '200.00', 'dealer_price' => null]);
        $archived = Product::factory()->create(['active' => false, 'archived_at' => now(), 'mrp' => '200.00', 'dealer_price' => '150.00']);
        $notChosen = Product::factory()->create(['active' => false, 'archived_at' => null, 'mrp' => '200.00', 'dealer_price' => '150.00']);

        $this->publish($editor, [$ready->id, $noDealerPrice->id, $archived->id]);

        $this->assertTrue($ready->fresh()->active);
        $this->assertFalse($noDealerPrice->fresh()->active);
        $this->assertFalse($archived->fresh()->active);
        $this->assertFalse($notChosen->fresh()->active);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.bulk_published', 'subject' => 'catalog']);
    }

    public function test_staff_without_catalog_rights_cannot_publish(): void
    {
        $draft = Product::factory()->create(['active' => false, 'archived_at' => null, 'mrp' => '200.00', 'dealer_price' => '150.00']);

        $this->expectException(HttpException::class);
        try {
            $this->publish(User::factory()->staff('inventory')->create(), [$draft->id]);
        } finally {
            $this->assertFalse($draft->fresh()->active);
        }
    }

    /** @param  list<int>  $ids */
    private function publish(User $user, array $ids): void
    {
        $this->actingAs($user);
        $request = Request::create('/commands/products/bulk-publish', 'POST', ['product_ids' => $ids]);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        app(ProductRegistryController::class)->bulkPublish($request);
    }
}
