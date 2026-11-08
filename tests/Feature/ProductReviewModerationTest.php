<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SiteFeedback;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProductReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    private const ReviewText = 'I have been using this product for a while and I am happy with it.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array<string, int|string>
     */
    private function reviewForm(array $overrides = []): array
    {
        return [
            'name' => 'Visitor Reviewer',
            'location' => 'Rangpur',
            'rating' => 4,
            'message' => self::ReviewText,
            ...$overrides,
        ];
    }

    public function test_visitor_review_waits_until_it_is_approved(): void
    {
        $product = Product::factory()->create();

        $this->post('/products/'.$product->id.'/reviews', $this->reviewForm())
            ->assertRedirect('/products/'.$product->id.'#product-reviews')
            ->assertSessionHas('product_review_received');

        $this->assertDatabaseHas('site_feedback', [
            'product_id' => $product->id,
            'name' => 'Visitor Reviewer',
            'status' => 'pending',
            'source' => 'public',
            'rating' => 4,
        ]);

        $this->get('/products/'.$product->id)->assertOk()->assertDontSee(self::ReviewText);
    }

    public function test_owner_approves_a_review_through_the_existing_feedback_moderation(): void
    {
        $product = Product::factory()->create();
        $this->post('/products/'.$product->id.'/reviews', $this->reviewForm())->assertRedirect();
        $review = SiteFeedback::query()->firstOrFail();

        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->post('/commands/feedback/'.$review->id, ['status' => 'approved'])
            ->assertSessionHas('success');

        $this->assertTrue($review->fresh()->isApproved());
        auth()->logout();
        $this->get('/products/'.$product->id)->assertOk()
            ->assertSee(self::ReviewText)
            ->assertSee('Visitor Reviewer');
    }

    public function test_rejected_review_never_shows(): void
    {
        $product = Product::factory()->create();
        SiteFeedback::factory()->create(['product_id' => $product->id, 'status' => 'rejected', 'message' => 'Rejected review text for this product.']);

        $this->get('/products/'.$product->id)->assertOk()->assertDontSee('Rejected review text for this product.');
    }

    public function test_review_shows_only_on_its_own_product(): void
    {
        $reviewed = Product::factory()->create();
        $other = Product::factory()->create();
        SiteFeedback::factory()->create(['product_id' => $reviewed->id, 'status' => 'approved', 'message' => 'Approved review of the first product.', 'moderated_at' => now()]);

        $this->get('/products/'.$reviewed->id)->assertOk()->assertSee('Approved review of the first product.');
        $this->get('/products/'.$other->id)->assertOk()->assertDontSee('Approved review of the first product.');
    }

    public function test_product_reviews_stay_off_the_homepage_testimonials(): void
    {
        $product = Product::factory()->create();
        SiteFeedback::factory()->create(['product_id' => $product->id, 'status' => 'approved', 'message' => 'Approved review of one product only.', 'moderated_at' => now()]);
        SiteFeedback::factory()->create(['product_id' => null, 'status' => 'approved', 'message' => 'General feedback about the company.', 'moderated_at' => now()]);

        $messages = array_column(SiteCustomization::getTestimonials(), 'text');

        $this->assertSame(['General feedback about the company.'], $messages);
    }

    public function test_moderation_list_names_the_reviewed_product(): void
    {
        $product = Product::factory()->create(['name' => 'Reviewed Test Product']);
        SiteFeedback::factory()->create(['product_id' => $product->id, 'status' => 'pending']);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->onPortal('sadmin')
            ->get('/feedback')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('data.feedback_items.0.product_name', 'Reviewed Test Product'));
    }

    public function test_review_form_refuses_html_short_text_and_robots(): void
    {
        $product = Product::factory()->create();

        $this->post('/products/'.$product->id.'/reviews', $this->reviewForm(['message' => 'too short']))->assertSessionHasErrors('message');
        $this->post('/products/'.$product->id.'/reviews', $this->reviewForm(['message' => 'A longer message with <script>alert(1)</script> inside it.']))->assertSessionHasErrors('message');
        $this->post('/products/'.$product->id.'/reviews', $this->reviewForm(['website' => 'http://spam.example']))->assertSessionHasErrors('website');
        $this->post('/products/'.$product->id.'/reviews', $this->reviewForm(['rating' => 9]))->assertSessionHasErrors('rating');

        $this->assertSame(0, SiteFeedback::query()->count());
    }

    public function test_a_hidden_product_cannot_be_reviewed(): void
    {
        $product = Product::factory()->create(['active' => false]);

        $this->post('/products/'.$product->id.'/reviews', $this->reviewForm())->assertNotFound();
        $this->assertSame(0, SiteFeedback::query()->count());
    }

    public function test_review_is_escaped_on_the_page(): void
    {
        $product = Product::factory()->create();
        SiteFeedback::factory()->create(['product_id' => $product->id, 'status' => 'approved', 'name' => 'A & B', 'moderated_at' => now()]);

        $this->get('/products/'.$product->id)->assertOk()->assertSee('A &amp; B', false);
    }

    public function test_deleting_a_product_removes_its_reviews(): void
    {
        $product = Product::factory()->create();
        SiteFeedback::factory()->create(['product_id' => $product->id]);

        $product->delete();

        $this->assertSame(0, SiteFeedback::query()->count());
    }
}
