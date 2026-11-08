<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductConcern;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductConcernFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_no_concern_tags_exist_until_the_owner_adds_one(): void
    {
        Product::factory()->create();

        $this->assertSame(0, ProductConcern::query()->count());
        $this->withCookie('locale', 'en')->get('/products')->assertOk()
            ->assertDontSee(__('site.catalog_concern_title'))
            ->assertDontSee('type="radio" name="concern"', false);
    }

    public function test_catalogue_filters_by_concern_tag(): void
    {
        $digestion = ProductConcern::factory()->create(['name_bn' => 'হজম', 'name_en' => 'Digestion']);
        $skin = ProductConcern::factory()->create(['name_bn' => 'ত্বক', 'name_en' => 'Skin care']);
        Product::factory()->create(['name' => 'Tagged digestion product'])->concerns()->attach($digestion);
        Product::factory()->create(['name' => 'Tagged skin product'])->concerns()->attach($skin);
        Product::factory()->create(['name' => 'Untagged product']);

        $this->withCookie('locale', 'en')->get('/products')->assertOk()
            ->assertSee(__('site.catalog_concern_title'))
            ->assertSee('Digestion')
            ->assertSee('Skin care')
            ->assertSee('Untagged product');

        $this->withCookie('locale', 'en')->get('/products?concern='.$digestion->id)->assertOk()
            ->assertSee('Tagged digestion product')
            ->assertDontSee('Tagged skin product')
            ->assertDontSee('Untagged product');
    }

    public function test_filter_labels_use_the_visitors_language(): void
    {
        $concern = ProductConcern::factory()->create(['name_bn' => 'হজম', 'name_en' => 'Digestion']);
        Product::factory()->create()->concerns()->attach($concern);

        $this->withCookie('locale', 'bn')->get('/products')->assertOk()->assertSee('হজম')->assertDontSee('Digestion');
    }

    public function test_a_tag_with_only_hidden_products_is_not_offered(): void
    {
        $concern = ProductConcern::factory()->create(['name_en' => 'Only on a draft']);
        Product::factory()->create(['active' => false])->concerns()->attach($concern);

        $this->withCookie('locale', 'en')->get('/products')->assertOk()->assertDontSee('Only on a draft');
    }

    public function test_product_page_links_its_tags_to_the_filter(): void
    {
        $concern = ProductConcern::factory()->create(['name_en' => 'Digestion']);
        $product = Product::factory()->create();
        $product->concerns()->attach($concern);

        $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk()
            ->assertSee('/products?concern='.$concern->id, false)
            ->assertSee('Digestion');
    }

    public function test_owner_can_add_rename_and_delete_a_tag(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($owner)->post('/commands/product-concerns', ['name_bn' => 'হজম', 'name_en' => 'Digestion'])
            ->assertSessionHas('success');
        $concern = ProductConcern::query()->firstOrFail();

        $this->patch('/commands/product-concerns/'.$concern->id, ['name_bn' => 'হজম শক্তি', 'name_en' => 'Digestive care'])
            ->assertSessionHas('success');
        $this->assertSame('Digestive care', $concern->fresh()->name_en);

        $product = Product::factory()->create();
        $product->concerns()->attach($concern);
        $this->delete('/commands/product-concerns/'.$concern->id)->assertSessionHas('success');

        $this->assertSame(0, ProductConcern::query()->count());
        $this->assertSame(0, $product->concerns()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'product_concern.deleted']);
    }

    public function test_tag_filter_change_shows_at_once_after_saving_a_product(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $concern = ProductConcern::factory()->create(['name_en' => 'Digestion']);
        $product = Product::factory()->create(['registry_version' => 1]);

        $this->withCookie('locale', 'en')->get('/products')->assertOk()->assertDontSee('Digestion');

        $this->actingAs($owner)->patch('/commands/products/'.$product->id, [
            'concern_ids' => [$concern->id],
            'sync_concerns' => '1',
            'reason' => 'Adding a concern tag',
            'version' => '1',
        ])->assertRedirect();
        $this->assertSame([$concern->id], $product->concerns()->pluck('product_concerns.id')->all());

        auth()->logout();
        $this->withCookie('locale', 'en')->get('/products')->assertOk()->assertSee('Digestion');

        $this->actingAs($owner)->patch('/commands/products/'.$product->id, [
            'sync_concerns' => '1',
            'reason' => 'Removing the concern tag',
            'version' => '2',
        ])->assertRedirect();
        $this->assertSame(0, $product->concerns()->count());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cureWordingProvider(): array
    {
        return [
            'english cure' => ['হজম', 'Cures diabetes'],
            'english treatment' => ['হজম', 'Asthma treatment'],
            'bangla cure' => ['ডায়াবেটিস নিরাময়', 'Blood sugar'],
            'bangla treatment' => ['গ্যাসের চিকিৎসা', 'Gas'],
            'bangla guarantee' => ['শতভাগ ফল', 'Results'],
        ];
    }

    #[DataProvider('cureWordingProvider')]
    public function test_tag_names_with_cure_wording_are_refused(string $nameBn, string $nameEn): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($owner)->post('/commands/product-concerns', ['name_bn' => $nameBn, 'name_en' => $nameEn])
            ->assertSessionHasErrors();

        $this->assertSame(0, ProductConcern::query()->count());
    }

    public function test_ordinary_tag_names_are_accepted_even_when_they_contain_similar_letters(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($owner)->post('/commands/product-concerns', ['name_bn' => 'স্বাস্থ্য যত্ন', 'name_en' => 'Health and secure sleep'])
            ->assertSessionHasNoErrors();
    }

    public function test_staff_without_catalog_permission_cannot_manage_tags(): void
    {
        $staff = User::factory()->create(['role' => 'accounts']);

        $this->actingAs($staff)->post('/commands/product-concerns', ['name_bn' => 'হজম', 'name_en' => 'Digestion'])
            ->assertForbidden();
    }
}
