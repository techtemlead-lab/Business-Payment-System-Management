<?php

namespace Tests\Feature;

use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalLatencyHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_skips_full_product_catalog_hydrate(): void
    {
        Product::factory()->count(5)->create(['active' => true]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('section', 'overview')
                ->missing('data.products')
                ->missing('data.products_truncated')
                ->has('translations.portal')
                ->has('userManualChapters')
                ->has('portalCatalog')
                ->has('dashboardControls'));
    }

    public function test_catalog_still_hydrates_products_for_partners(): void
    {
        Product::factory()->count(3)->create(['active' => true]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'catalog')
                ->has('data.products', 3));
    }

    public function test_branches_section_aggregates_stock_without_per_branch_queries(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $branch = CompanyBranch::factory()->create([
            'code' => 'LAT-01',
            'name' => 'Latency Hub',
            'is_active' => true,
            'is_stock_hub' => true,
        ]);
        $products = Product::factory()->count(3)->create(['active' => true]);
        foreach ($products as $index => $product) {
            BranchProductStock::query()->create([
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'quantity' => ($index + 1) * 4,
            ]);
        }

        DB::enableQueryLog();

        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/branches')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'branches')
                ->where('data.branches.data', fn ($branches) => collect($branches)->contains(
                    fn ($row) => (int) ($row['id'] ?? 0) === $branch->id
                        && (int) ($row['stock_skus'] ?? -1) === 3
                        && (int) ($row['stock_units'] ?? -1) === 24
                )));

        $standaloneStockQueries = collect(DB::getQueryLog())->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'branch_product_stock')
                && ! str_contains($sql, 'from "company_branches"')
                && ! str_contains($sql, 'from company_branches');
        });

        $this->assertTrue(
            $standaloneStockQueries->isEmpty(),
            'Expected branch stock totals to come from withCount/withSum on company_branches, not per-branch stock queries.',
        );
    }

    public function test_shared_tour_payload_does_not_duplicate_manual_chapters(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tour.chapters', [])
                ->has('userManualChapters'));
    }
}
