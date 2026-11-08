<?php

namespace Tests\Feature;

use App\Domain\Catalog\ProductFacts;
use App\Models\Product;
use App\Models\ProductConcern;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProductFactsTest extends TestCase
{
    use RefreshDatabase;

    /** Fixed wording that used to appear on every product page, in both languages. */
    private const RetiredClaims = [
        '100% natural herbs', 'GMP', 'Safe to use', 'Pure herbs', 'Carefully made', 'No harsh chemicals',
        'Checked by our health advisers', 'Use as written on the pack', 'Keep in a cool, dry place',
        'ভেষজ উপাদানে তৈরি',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array<string, string>
     */
    private function filledFacts(): array
    {
        return [
            'manufacturer_bn' => 'পরীক্ষা ল্যাবরেটরিজ',
            'manufacturer_en' => 'Test Laboratories',
            'product_system_bn' => 'ইউনানি',
            'product_system_en' => 'Unani',
            'registration_number' => 'TEST-REG-0001',
            'pack_size_bn' => '৬০টি ক্যাপসুল',
            'pack_size_en' => '60 capsules',
            'ingredients_bn' => 'পরীক্ষার উপাদান',
            'ingredients_en' => 'Test ingredient list',
            'dose_bn' => 'পরীক্ষার মাত্রা',
            'dose_en' => 'Test dose text',
            'warnings_bn' => 'পরীক্ষার সতর্কতা',
            'warnings_en' => 'Test warning text',
            'storage_bn' => 'পরীক্ষার সংরক্ষণ',
            'storage_en' => 'Test storage text',
            'quality_points_bn' => "প্রথম কথা\nদ্বিতীয় কথা",
            'quality_points_en' => "First test point\n\nSecond test point",
        ];
    }

    public function test_new_fact_columns_are_all_nullable_and_blank_on_an_existing_product(): void
    {
        $product = Product::factory()->create();

        foreach (ProductFacts::columns() as $column) {
            $this->assertTrue(Schema::hasColumn('products', $column), $column);
            $this->assertNull($product->fresh()->getAttribute($column), $column);
        }
    }

    public function test_owner_can_save_and_clear_facts_from_the_product_editor(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $product = Product::factory()->create(['registry_version' => 3]);

        $this->actingAs($owner)->patch('/commands/products/'.$product->id, [
            ...$this->filledFacts(),
            'reason' => 'Adding facts from the product file',
            'version' => '3',
        ])->assertRedirect();

        $product->refresh();
        $this->assertSame('Test Laboratories', $product->getAttribute('manufacturer_en'));
        $this->assertSame('TEST-REG-0001', $product->getAttribute('registration_number'));

        $this->patch('/commands/products/'.$product->id, [
            'manufacturer_en' => '',
            'registration_number' => '',
            'reason' => 'Removing facts that are not confirmed',
            'version' => (string) $product->registry_version,
        ])->assertRedirect();

        $product->refresh();
        $this->assertNull($product->getAttribute('manufacturer_en'));
        $this->assertNull($product->getAttribute('registration_number'));
        $this->assertSame('Test ingredient list', $product->getAttribute('ingredients_en'));
    }

    public function test_editor_receives_saved_facts_and_tag_choices(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $product = Product::factory()->create($this->filledFacts());
        $concern = ProductConcern::factory()->create();
        $product->concerns()->attach($concern);

        $this->actingAs($owner)->onPortal('sadmin')->get('/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('ProductEditor')
                ->where('product.manufacturer_en', 'Test Laboratories')
                ->where('product.concern_ids', [$concern->id])
                ->where('product_concerns.0.id', $concern->id)
                ->has('product_fact_fields', count(ProductFacts::BilingualFields)));
    }

    public function test_staff_without_catalog_permission_cannot_write_facts(): void
    {
        $staff = User::factory()->create(['role' => 'accounts']);
        $product = Product::factory()->create(['registry_version' => 1]);

        $this->actingAs($staff)->patch('/commands/products/'.$product->id, [
            'manufacturer_en' => 'Not allowed',
            'reason' => 'Trying to change a fact',
            'version' => '1',
        ])->assertForbidden();

        $this->assertNull($product->fresh()->getAttribute('manufacturer_en'));
    }

    public function test_filled_facts_show_in_the_visitors_language(): void
    {
        $product = Product::factory()->create($this->filledFacts());

        $english = $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk();
        foreach (['Test Laboratories', 'Unani', 'TEST-REG-0001', '60 capsules', 'Test ingredient list', 'Test dose text', 'Test warning text', 'Test storage text', 'First test point', 'Second test point'] as $fact) {
            $english->assertSee($fact);
        }
        $english->assertSee(__('product_facts.manufacturer'));

        $bangla = $this->withCookie('locale', 'bn')->get('/products/'.$product->id)->assertOk();
        foreach (['পরীক্ষা ল্যাবরেটরিজ', 'ইউনানি', '৬০টি ক্যাপসুল', 'পরীক্ষার উপাদান', 'প্রথম কথা'] as $fact) {
            $bangla->assertSee($fact);
        }
        $bangla->assertDontSee('Test Laboratories');
    }

    public function test_a_fact_filled_in_one_language_still_shows_in_the_other(): void
    {
        $product = Product::factory()->create(['manufacturer_bn' => 'শুধু বাংলা নাম']);

        $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk()->assertSee('শুধু বাংলা নাম');
    }

    public function test_blank_facts_hide_their_rows_and_tabs(): void
    {
        $product = Product::factory()->create(['description' => null]);

        $page = $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk();

        foreach (['manufacturer', 'product_system', 'registration_number', 'pack_size'] as $label) {
            $page->assertDontSee('<th>'.__('product_facts.'.$label).'</th>', false);
        }
        $page->assertDontSee('id="description-tab"', false)
            ->assertDontSee('id="quality-tab"', false)
            ->assertDontSee('id="usage-tab"', false)
            ->assertSee('id="specification-tab"', false)
            ->assertSee('<div id="specification-panel" role="tabpanel" aria-labelledby="specification-tab" >', false);
    }

    public function test_one_filled_usage_fact_shows_only_its_own_row(): void
    {
        $product = Product::factory()->create(['warnings_en' => 'Keep away from test children']);

        $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk()
            ->assertSee('id="usage-tab"', false)
            ->assertSee('Keep away from test children')
            ->assertSee(__('product_facts.warnings'))
            ->assertDontSee(__('site.pd_directions_title'))
            ->assertDontSee(__('site.pd_storage_title'));
    }

    public function test_no_fixed_claim_or_directions_appear_on_a_product_page_in_either_language(): void
    {
        $product = Product::factory()->create(['description' => null]);

        foreach (['en', 'bn'] as $locale) {
            $page = $this->withCookie('locale', $locale)->get('/products/'.$product->id)->assertOk();
            foreach (self::RetiredClaims as $claim) {
                $page->assertDontSee($claim);
            }
        }

        foreach (['pd_tag_natural', 'pd_tag_gmp', 'pd_tag_safe', 'pd_default_description', 'pd_directions_body', 'pd_storage_body', 'pd_feature_natural_body'] as $retiredKey) {
            $this->assertArrayNotHasKey($retiredKey, trans('site', [], 'en'), $retiredKey);
            $this->assertArrayNotHasKey($retiredKey, trans('site', [], 'bn'), $retiredKey);
        }
    }

    public function test_general_doctor_note_shows_and_the_owner_can_change_it(): void
    {
        $product = Product::factory()->create();

        $this->withCookie('locale', 'bn')->get('/products/'.$product->id)->assertOk()
            ->assertSee('রোগ নির্ণয় ও চিকিৎসার জন্য নিবন্ধিত চিকিৎসকের পরামর্শ নিন।');

        SiteCustomization::save(['product_medical_note' => 'Owner written general note']);

        $this->withCookie('locale', 'bn')->get('/products/'.$product->id)->assertOk()
            ->assertSee('Owner written general note')
            ->assertDontSee('রোগ নির্ণয় ও চিকিৎসার জন্য নিবন্ধিত চিকিৎসকের পরামর্শ নিন।');
    }

    public function test_about_page_default_has_no_chemical_free_claim(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $this->withCookie('locale', $locale)->get('/about')->assertOk()
                ->assertDontSee('chemical-free')
                ->assertDontSee('রাসায়নিকমুক্ত');
        }
    }

    public function test_old_saved_chemical_free_about_text_is_treated_as_unchanged_default(): void
    {
        SiteCustomization::save([
            'about_hero_lead' => 'Madina Natural Health Care is a licensed, single-owner distributor (Trade License 2026-00563, Pirganj Municipality) with its head office on Thana Road, Pirganj 5470, Rangpur. We supply chemical-free herbal and Holistic Nutracare nutrition products through authorized dealers and sub-dealers across Bangladesh.',
        ]);

        $this->withCookie('locale', 'en')->get('/about')->assertOk()->assertDontSee('chemical-free');
    }

    public function test_about_text_stays_editable_by_the_owner(): void
    {
        SiteCustomization::save(['about_hero_lead' => 'Owner written about text']);

        $this->withCookie('locale', 'en')->get('/about')->assertOk()->assertSee('Owner written about text');
    }
}
