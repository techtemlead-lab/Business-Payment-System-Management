<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The product list opens for more people than the product page does, so the list must only
 * offer the page, and New Product, to people the server will really let through.
 */
class ProductPageAccessTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->product = Product::factory()->create();
    }

    /** @return array<string, array{string, string, bool}> */
    public static function rolesOnTheProductList(): array
    {
        return [
            'support' => ['support', 'manage', false],
            'account manager' => ['account_manager', 'manage', false],
            'finance' => ['finance', 'manage', true],
            'inventory' => ['inventory', 'manage', true],
            'editor' => ['editor', 'manage', true],
        ];
    }

    #[DataProvider('rolesOnTheProductList')]
    public function test_the_list_offers_the_product_page_only_to_those_who_may_open_it(string $role, string $portal, bool $mayOpen): void
    {
        $person = User::factory()->staff($role)->create();

        $this->actingAs($person)->onPortal($portal)->get('/catalog')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.can_open_product', $mayOpen));
        $this->actingAs($person)->onPortal($portal)->get('/products/'.$this->product->id)->assertStatus($mayOpen ? 200 : 403);
    }

    public function test_md_and_chairman_open_the_product_list_only_when_given_a_view_right(): void
    {
        foreach (['md', 'chairman'] as $role) {
            // D11 (SA-07): payroll only by default, so neither the list nor a product page opens.
            $default = User::factory()->staff($role)->create();
            $this->actingAs($default)->onPortal('sadmin')->get('/catalog')->assertForbidden();
            $this->actingAs($default)->onPortal('sadmin')->get('/products/'.$this->product->id)->assertForbidden();

            // Given order viewing, they see the list but are still not offered the product editor.
            $viewer = User::factory()->staff($role)->create(['profile' => ['action_permissions' => ['orders' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]]]]);
            $this->actingAs($viewer)->onPortal('sadmin')->get('/catalog')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('data.can_open_product', false));
            $this->actingAs($viewer)->onPortal('sadmin')->get('/products/'.$this->product->id)->assertForbidden();
        }
    }

    public function test_the_editor_is_not_offered_a_new_product_the_server_would_refuse(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->onPortal('manage')->get('/catalog')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.can_create_product', false));
        $this->actingAs($editor)->onPortal('manage')->post('/commands/products', ['name' => 'Refused', 'mrp' => '100', 'dealer_price' => '60', 'sub_dealer_price' => '70', 'initial_stock' => '0', 'low_stock_at' => '0', 'reason' => 'Trying a new product'])
            ->assertForbidden();
    }

    /** @return array<string, array{list<string>, bool}> */
    public static function rightsForTheNewProductForm(): array
    {
        return [
            'catalog alone' => [['catalog'], false],
            'catalog and prices, no stock' => [['catalog', 'pricing'], false],
            'catalog, prices and stock' => [['catalog', 'pricing', 'inventory'], true],
        ];
    }

    /** @param list<string> $permissions */
    #[DataProvider('rightsForTheNewProductForm')]
    public function test_new_product_needs_every_right_its_form_uses(array $permissions, bool $offered): void
    {
        $person = User::factory()->create(['role' => 'employee', 'status' => 'active', 'profile' => ['permissions' => $permissions]]);

        $this->actingAs($person)->onPortal('manage')->get('/catalog')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.can_create_product', $offered));
    }

    public function test_the_owner_keeps_both(): void
    {
        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/catalog')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.can_open_product', true)->where('data.can_create_product', true));
    }
}
