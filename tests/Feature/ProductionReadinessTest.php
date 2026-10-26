<?php

namespace Tests\Feature;

use App\Domain\Finance\ReportData;
use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_cms_rejects_executable_banner_link_schemes(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->postJson('/commands/customization', ['customization' => [
            'slides' => [['image' => '/madina-header-logo.png', 'alt' => 'Audit banner', 'link' => 'javascript:alert(1)']],
        ]])->assertUnprocessable();
    }

    public function test_saving_product_showcase_preserves_existing_company_contact_settings(): void
    {
        SiteCustomization::save(['company_intro_title' => 'Approved Company Introduction', 'contact_phone' => '+8801700000101']);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->post('/commands/customization', ['customization' => ['featured_products_title' => 'Updated Product Showcase']])->assertRedirect();
        $this->assertSame('Approved Company Introduction', SiteCustomization::all()['company_intro_title']);
        $this->assertSame('+8801700000101', SiteCustomization::all()['contact_phone']);
    }

    public function test_commission_refund_rejects_an_order_without_posted_commissions(): void
    {
        $this->enableFinancialPosting();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $buyer = User::factory()->dealer()->create();
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(), 'reference' => 'AUDIT-COLLECTION', 'amount' => '100.00', 'channel' => 'Bank',
        ]);
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/finance/orders/'.$order->id.'/commission-refunds', [
            'refund_amount' => '100.00', 'reference' => 'AUDIT-REFUND', 'reason' => 'Audit refund of an unposted order',
            'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable();
    }

    public function test_public_low_stock_label_uses_the_configured_product_threshold(): void
    {
        Product::factory()->create(['name' => 'Threshold Audit Product', 'stock' => 8, 'low_stock_at' => 10, 'featured' => true]);
        $this->get('/')->assertOk()->assertSee('Low Stock');
    }

    #[TestWith(['JaVaScRiPt:alert(1)'])]
    #[TestWith(['data:text/html,<script>alert(1)</script>'])]
    #[TestWith(['//unapproved.example.test/path'])]
    #[TestWith(["java\nscript:alert(1)"])]
    public function test_unsafe_link_schemes_are_rejected_without_changing_existing_content(string $link): void
    {
        SiteCustomization::save(['company_intro_title' => 'Approved introduction']);
        $this->actingAs(User::factory()->staff('editor')->create())->postJson('/commands/customization', [
            'customization' => ['slides' => [['image' => '/branding/logo.png', 'link' => $link]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('customization.slides.0.link');
        $this->assertSame('Approved introduction', SiteCustomization::all()['company_intro_title']);
    }

    public function test_existing_unsafe_links_are_neutralized_when_content_is_rendered(): void
    {
        DB::table('content_pages')->insert(['slug' => 'frontend_customization', 'title' => 'Legacy content',
            'body' => json_encode(['slides' => [['image' => '/branding/logo.png', 'link' => 'javascript:alert(1)']]]), 'published' => true]);
        $this->get('/')->assertOk()->assertDontSee('javascript:alert(1)', false);
    }

    public function test_malformed_content_shapes_return_validation_errors_instead_of_runtime_warnings(): void
    {
        $this->actingAs(User::factory()->staff('editor')->create())->postJson('/commands/customization', [
            'customization' => ['company_intro_title' => ['unexpected' => 'array']],
        ])->assertUnprocessable()->assertJsonValidationErrors('customization.company_intro_title');
        $this->postJson('/commands/customization', [
            'customization' => ['slides' => [['image' => ['unexpected' => 'array']]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('customization.slides');
    }

    public function test_rank_report_uses_dhaka_boundaries_even_with_a_non_utc_session(): void
    {
        $partner = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        DB::table('dealer_ranks')->insert(['user_id' => $partner->id, 'current_rank' => 'am', 'achieved_at' => '2026-09-15 06:00:00',
            'qualification_history' => json_encode([['rank' => 'mo', 'achieved_at' => '2026-09-01T06:00:00Z'], ['rank' => 'am', 'achieved_at' => '2026-09-14T19:00:00Z']]),
            'created_at' => now(), 'updated_at' => now()]);
        DB::statement("SET LOCAL TIME ZONE 'America/Los_Angeles'");
        $rows = app(ReportData::class)->query($finance, 'rank-achievements', '2026-09-15', '2026-09-15')['query']->get();
        $this->assertCount(1, $rows);
        $this->assertSame('am', $rows[0]->rank);
    }

    public function test_catalog_search_finds_skus_and_preserves_partner_publication_scope(): void
    {
        $draft = Product::factory()->create(['sku' => 'DRAFT-SKU-101', 'active' => false]);
        $this->actingAs(User::factory()->staff()->create())->get('/company/catalog?q=DRAFT-SKU-101')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('data.products', 1)->where('data.products.0.id', $draft->id));
        $this->actingAs(User::factory()->dealer()->create())->get('/workspace/catalog?q=DRAFT-SKU-101')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('data.products', 0));
    }
}
