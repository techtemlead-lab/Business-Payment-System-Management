<?php

namespace Tests\Feature;

use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockOnHandRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_register_shows_channel_prices_and_defaults_to_head_office(): void
    {
        $headOffice = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        CompanyBranch::factory()->create([
            'code' => 'REV-HO',
            'name' => 'Review Head Office',
        ]);
        $owner = User::factory()->create([
            'role' => 'super_admin',
            'status' => 'active',
            'active_branch_id' => null,
        ]);
        $napa = Product::factory()->create([
            'name' => 'Napa',
            'sku' => 'NAPA-100',
            'strength' => '100',
            'category' => 'Tablet',
            'sub_category' => 'Pain',
            'unit' => 'Strip',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'sub_dealer_price' => '160.00',
            'expires_on' => '2027-03-15',
            'stock' => 40,
            'low_stock_at' => 10,
        ]);
        Product::factory()->create([
            'name' => 'Plain Balm',
            'sku' => 'BALM-1',
            'strength' => null,
            'mrp' => '50.00',
            'dealer_price' => '40.00',
        ]);
        BranchProductStock::query()->create([
            'branch_id' => $headOffice->id,
            'product_id' => $napa->id,
            'quantity' => 12,
            'low_stock_at' => 10,
        ]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/inventory')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'inventory')
                ->where('workspaceBranches.active_branch_id', $headOffice->id)
                ->where('data.active_branch_id', $headOffice->id)
                ->where('data.active_branch.name', 'Head Office · Pirganj')
                ->where('data.products', function (mixed $products): bool {
                    $rows = collect($products);
                    $napa = $rows->firstWhere('sku', 'NAPA-100');
                    $plain = $rows->firstWhere('sku', 'BALM-1');

                    return is_array($napa)
                        && $napa['register_name'] === 'Napa-100'
                        && $napa['unit'] === 'Strip'
                        && $napa['category'] === 'Tablet'
                        && $napa['sub_category'] === 'Pain'
                        && $napa['mrp'] === '200.00'
                        && $napa['dealer_price'] === '140.00'
                        && $napa['dealer_discount_pct'] === '30.00'
                        && $napa['sub_dealer_price'] === '160.00'
                        && $napa['sub_dealer_discount_pct'] === '20.00'
                        && $napa['expires_on'] === '2027-03-15'
                        && $napa['branch_stock'] === 12
                        && $napa['company_stock'] === 40
                        && is_array($plain)
                        && $plain['register_name'] === 'Plain Balm';
                }));
    }

    public function test_saved_branch_stays_selected_on_the_stock_register(): void
    {
        $review = CompanyBranch::factory()->create([
            'code' => 'REV-HO',
            'name' => 'Review Head Office',
        ]);
        $owner = User::factory()->create([
            'role' => 'super_admin',
            'status' => 'active',
            'active_branch_id' => $review->id,
        ]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/inventory')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspaceBranches.active_branch_id', $review->id)
                ->where('data.active_branch.name', 'Review Head Office'));
    }
}
