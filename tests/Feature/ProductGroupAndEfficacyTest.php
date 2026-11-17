<?php

namespace Tests\Feature;

use App\Domain\Catalog\ProductFacts;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProductGroupAndEfficacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_group_and_efficacy_are_optional_and_saved_in_both_languages(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $product = Product::factory()->create(['registry_version' => 1]);

        $this->actingAs($owner)->patch('/commands/products/'.$product->id, [
            'group_bn' => 'প্যারাসিটামল',
            'group_en' => 'Paracetamol',
            'efficacy_bn' => 'জ্বরে সাহায্য করে',
            'efficacy_en' => 'Helps with fever',
            'reason' => 'Adding group and efficacy',
            'version' => '1',
        ])->assertRedirect();

        $product->refresh();
        $this->assertSame('Paracetamol', $product->group_en);
        $this->assertSame('প্যারাসিটামল', $product->group_bn);
        $this->assertSame('Helps with fever', $product->efficacy_en);

        $this->patch('/commands/products/'.$product->id, [
            'group_bn' => '', 'group_en' => '', 'efficacy_en' => '',
            'reason' => 'Clearing group and efficacy',
            'version' => (string) $product->registry_version,
        ])->assertRedirect();

        $product->refresh();
        $this->assertNull($product->group_en);
        $this->assertNull($product->efficacy_en);
        $this->assertSame('জ্বরে সাহায্য করে', $product->efficacy_bn);
    }

    public function test_a_too_long_group_is_refused(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $product = Product::factory()->create(['registry_version' => 1]);

        $this->actingAs($owner)->patch('/commands/products/'.$product->id, [
            'group_en' => str_repeat('a', 121),
            'reason' => 'Too long group',
            'version' => '1',
        ])->assertSessionHasErrors('group_en');
    }

    public function test_staff_without_catalog_permission_cannot_write_a_group(): void
    {
        $staff = User::factory()->create(['role' => 'accounts']);
        $product = Product::factory()->create(['registry_version' => 1]);

        $this->actingAs($staff)->patch('/commands/products/'.$product->id, [
            'group_en' => 'Not allowed', 'reason' => 'Trying to set a group', 'version' => '1',
        ])->assertForbidden();

        $this->assertNull($product->fresh()->group_en);
    }

    public function test_the_editor_gets_group_suggestions_without_repeats(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        Product::factory()->create(['group_en' => 'Paracetamol', 'group_bn' => 'প্যারাসিটামল']);
        Product::factory()->create(['group_en' => 'Paracetamol']);
        $product = Product::factory()->create(['group_en' => 'Antacid']);

        $this->assertSame(['Antacid', 'Paracetamol'], ProductFacts::groupSuggestions()['en']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('product_group_suggestions.en', ['Antacid', 'Paracetamol'])
                ->where('product_group_suggestions.bn', ['প্যারাসিটামল']));
    }

    public function test_the_public_page_shows_group_efficacy_and_warnings_under_the_description(): void
    {
        $product = Product::factory()->create([
            'description' => 'Plain test description',
            'group_en' => 'Paracetamol', 'efficacy_en' => 'Helps with test fever', 'warnings_en' => 'Not for test children',
        ]);

        $page = $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk();
        $page->assertSee('Paracetamol')
            ->assertSee('<th>'.__('product_facts.group').'</th>', false)
            ->assertSee('Helps with test fever')
            ->assertSee('Not for test children');

        $html = $page->getContent();
        $descriptionAt = strpos($html, 'Plain test description');
        $this->assertNotFalse($descriptionAt);
        $this->assertGreaterThan($descriptionAt, strpos($html, 'Helps with test fever'));
        $this->assertGreaterThan(strpos($html, 'Helps with test fever'), strpos($html, 'Not for test children'));
        $this->assertLessThan(strpos($html, 'id="specification-panel"'), strpos($html, 'Not for test children'));
    }

    public function test_efficacy_alone_opens_the_description_tab_and_blank_ones_show_nothing(): void
    {
        $withEfficacy = Product::factory()->create(['description' => null, 'efficacy_bn' => 'শুধু কার্যকারিতা']);
        $this->withCookie('locale', 'bn')->get('/products/'.$withEfficacy->id)->assertOk()
            ->assertSee('id="description-tab"', false)
            ->assertSee('শুধু কার্যকারিতা');

        $blank = Product::factory()->create(['description' => null]);
        $this->withCookie('locale', 'en')->get('/products/'.$blank->id)->assertOk()
            ->assertDontSee('id="description-tab"', false)
            ->assertDontSee('<th>'.__('product_facts.group').'</th>', false);
    }

    public function test_the_office_list_shows_the_group_and_searches_by_it(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        Product::factory()->create(['name' => 'Napa Test', 'group_en' => 'Paracetamol']);
        Product::factory()->create(['name' => 'Other Test', 'group_en' => 'Antacid']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/catalog?q=Paracet')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('data.products', 1)
                ->where('data.products.0.name', 'Napa Test')
                ->where('data.products.0.group', 'Paracetamol'));
    }
}
