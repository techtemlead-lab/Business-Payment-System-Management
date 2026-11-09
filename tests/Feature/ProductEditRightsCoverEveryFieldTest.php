<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The product editor greys out the boxes a person may only look at. The server has to refuse
 * them too, whichever field the change arrives through.
 */
class ProductEditRightsCoverEveryFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @param  array<string, array<string, bool>>  $matrix
     */
    private function staffWith(array $matrix): User
    {
        return User::factory()->create(['role' => 'employee', 'status' => 'active', 'profile' => ['action_permissions' => $matrix]]);
    }

    private function product(): Product
    {
        return Product::create(['name' => 'Test Syrup', 'sku' => 'TEST-RIGHTS', 'category' => 'Test Category', 'unit' => 'pack', 'mrp' => '1000.00', 'dealer_price' => '700.00', 'sub_dealer_price' => '800.00', 'active' => true, 'strength' => '10 mg', 'batch_number' => 'OLD-BATCH', 'low_stock_at' => 10])->refresh();
    }

    /** @return array<string, array{string}> */
    public static function discountFields(): array
    {
        return ['dealer discount' => ['dealer_discount_pct'], 'sub-dealer discount' => ['sub_dealer_discount_pct']];
    }

    #[DataProvider('discountFields')]
    public function test_someone_who_may_only_view_prices_cannot_change_them_with_a_discount_percent(string $field): void
    {
        $product = $this->product();
        $staff = $this->staffWith([
            'catalog' => ['view' => true, 'create' => false, 'edit' => true, 'approve' => false],
            'pricing' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false],
        ]);

        $this->actingAs($staff)->patch('/commands/products/'.$product->id, [
            'version' => (string) $product->registry_version,
            'name' => 'Test Syrup',
            $field => '35',
            'reason' => 'Trying to change the price',
        ])->assertForbidden();

        $product->refresh();
        $this->assertSame('700.00', $product->dealer_price);
        $this->assertSame('800.00', $product->sub_dealer_price);
    }

    public function test_someone_who_may_edit_prices_can_still_use_the_discount_percent(): void
    {
        $product = $this->product();
        $staff = $this->staffWith([
            'pricing' => ['view' => true, 'create' => false, 'edit' => true, 'approve' => false],
        ]);

        $this->actingAs($staff)->patch('/commands/products/'.$product->id, [
            'version' => (string) $product->registry_version,
            'dealer_discount_pct' => '40',
            'reason' => 'New dealer discount agreed',
        ])->assertRedirect();

        $this->assertSame('600.00', $product->refresh()->dealer_price);
    }

    /** @return array<string, array{string, string}> */
    public static function catalogOnlyFields(): array
    {
        return [
            'strength' => ['strength', '500 mg'],
            'batch number' => ['batch_number', 'NEW-BATCH'],
            'expiry' => ['expires_on', '2030-01-31'],
            'sub-category' => ['sub_category', 'Changed'],
        ];
    }

    #[DataProvider('catalogOnlyFields')]
    public function test_someone_who_may_only_view_the_catalog_cannot_change_its_details_alongside_a_stock_edit(string $field, string $value): void
    {
        $product = $this->product();
        $before = $product->getAttribute($field);
        $staff = $this->staffWith([
            'catalog' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false],
            'inventory' => ['view' => true, 'create' => false, 'edit' => true, 'approve' => false],
        ]);

        $this->actingAs($staff)->patch('/commands/products/'.$product->id, [
            'version' => (string) $product->registry_version,
            'low_stock_at' => 25,
            $field => $value,
            'reason' => 'Changing product details',
        ])->assertForbidden();

        $product->refresh();
        $this->assertEquals($before, $product->getAttribute($field));
        $this->assertSame(10, $product->low_stock_at);
    }
}
