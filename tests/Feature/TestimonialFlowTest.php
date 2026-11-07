<?php

namespace Tests\Feature;

use App\Models\SiteFeedback;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Customer and partner reviews: people send them, website staff (the
 * `content` permission) check them, and only approved ones reach the site.
 */
class TestimonialFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reviewTextFields(): array
    {
        return [
            'name' => ['name'],
            'role' => ['role'],
            'location' => ['location'],
            'message' => ['message'],
        ];
    }

    #[DataProvider('reviewTextFields')]
    public function test_public_review_refuses_html_tags_in_every_text_field(string $field): void
    {
        $payload = $this->validReview();
        $payload[$field] = 'Nice products <script>alert(1)</script> for my whole family';

        $this->post('/feedback', $payload)->assertSessionHasErrors($field);

        $this->assertDatabaseCount('site_feedback', 0);
    }

    public function test_public_review_form_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/feedback', $this->validReview())->assertRedirect();
        }

        $this->post('/feedback', $this->validReview())->assertTooManyRequests();
        $this->assertDatabaseCount('site_feedback', 5);
    }

    public function test_public_review_with_the_hidden_trap_field_filled_is_refused(): void
    {
        $this->post('/feedback', [...$this->validReview(), 'website' => 'http://spam.example'])
            ->assertSessionHasErrors('website');

        $this->assertDatabaseCount('site_feedback', 0);
    }

    public function test_public_review_needs_a_name_a_rating_and_a_real_message(): void
    {
        $this->post('/feedback', ['name' => '', 'rating' => 9, 'message' => 'Too short'])
            ->assertSessionHasErrors(['name', 'rating', 'message']);

        $this->assertDatabaseCount('site_feedback', 0);
    }

    public function test_plain_text_with_symbols_is_kept_and_shown_safely(): void
    {
        $this->post('/feedback', [
            ...$this->validReview(),
            'message' => 'Price is fair & quality is 5/5, better than 1 < 2 brands I tried.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        SiteFeedback::query()->update(['status' => 'approved', 'moderated_at' => now()]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Price is fair &amp; quality is 5/5, better than 1 &lt; 2 brands I tried.', $html);
    }

    public function test_a_review_sent_without_role_or_place_shows_words_in_the_visitors_language(): void
    {
        $this->post('/feedback', [
            'name' => 'Rahima Begum',
            'rating' => 5,
            'message' => 'The herbal tea helped my mother sleep better every night.',
        ])->assertRedirect();

        $review = SiteFeedback::query()->sole();
        $this->assertSame('', $review->role);
        $this->assertSame('', $review->location);
        $review->update(['status' => 'approved', 'moderated_at' => now()]);

        $this->withSession(['locale' => 'en'])->get('/')
            ->assertOk()
            ->assertSee('Customer · Bangladesh')
            ->assertSee('5 out of 5 stars');

        $this->withSession(['locale' => 'bn'])->get('/')
            ->assertOk()
            ->assertSee(__('site.testimonial_role_customer', [], 'bn').' · '.__('site.testimonial_location_default', [], 'bn'))
            ->assertSee(__('site.testimonial_stars_label', ['count' => '৫'], 'bn'))
            ->assertDontSee('Verified Client');
    }

    public function test_older_reviews_saved_with_english_filler_words_show_bangla_words(): void
    {
        SiteFeedback::factory()->approved()->create([
            'name' => 'Karim Uddin',
            'role' => 'Authorized Dealer Partner',
            'location' => 'Bangladesh',
            'source' => 'dealer',
        ]);

        $this->withSession(['locale' => 'bn'])->get('/')
            ->assertOk()
            ->assertSee('Karim Uddin')
            ->assertSee(__('site.testimonial_role_dealer', [], 'bn'))
            ->assertDontSee('Authorized Dealer Partner');
    }

    public function test_dealer_review_without_role_shows_dealer(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'district' => 'Bogura']);

        $this->actingAs($dealer)->post('/commands/feedback', [
            'rating' => 4,
            'message' => 'Orders reach my shop on time and customers like the products.',
        ])->assertSessionHasNoErrors();

        $review = SiteFeedback::query()->sole();
        $this->assertSame('', $review->role);
        $this->assertSame('Bogura', $review->location);
        $review->update(['status' => 'approved', 'moderated_at' => now()]);

        $this->get('/')->assertOk()->assertSee('Dealer · Bogura');
    }

    public function test_dealer_review_refuses_html_tags(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer']);

        $this->actingAs($dealer)->post('/commands/feedback', [
            'rating' => 5,
            'message' => '<img src=x onerror=alert(1)> great products for my customers',
        ])->assertSessionHasErrors('message');

        $this->assertDatabaseCount('site_feedback', 0);
    }

    public function test_only_staff_with_the_content_permission_may_check_reviews(): void
    {
        $review = SiteFeedback::factory()->create();
        $accountManager = User::factory()->staff('account_manager')->create();
        $dealer = User::factory()->create(['role' => 'dealer']);
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($accountManager)->post('/commands/feedback/'.$review->id, ['status' => 'approved'])->assertForbidden();
        $this->actingAs($accountManager)->get('/company/feedback')->assertForbidden();
        $this->actingAs($dealer)->post('/commands/feedback/'.$review->id, ['status' => 'approved'])->assertForbidden();
        $this->assertSame('pending', $review->fresh()->status);

        $this->actingAs($editor)->get('/company/feedback')->assertOk();
        $this->actingAs($editor)->post('/commands/feedback/'.$review->id, ['status' => 'approved'])->assertSessionHas('success');
        $this->assertSame('approved', $review->fresh()->status);
    }

    public function test_rejected_and_pending_reviews_never_reach_the_site(): void
    {
        SiteFeedback::factory()->create(['name' => 'Waiting Person']);
        SiteFeedback::factory()->rejected()->create(['name' => 'Refused Person']);

        $this->get('/')->assertOk()->assertDontSee('Waiting Person')->assertDontSee('Refused Person');
    }

    public function test_content_staff_can_add_a_review_given_by_phone_and_it_is_shown_at_once(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->post('/commands/testimonials', [
            'name' => 'Nasima Akter',
            'role' => 'Shop owner',
            'location' => 'Rangpur',
            'rating' => 5,
            'message' => 'My customers ask for the honey every week.',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('site_feedback', [
            'name' => 'Nasima Akter',
            'source' => 'staff',
            'status' => 'approved',
            'moderated_by' => $editor->id,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'feedback.added_by_staff', 'actor_id' => $editor->id]);

        $this->get('/')->assertOk()
            ->assertSee('Nasima Akter')
            ->assertSee('Shop owner · Rangpur')
            ->assertSee('My customers ask for the honey every week.');
    }

    public function test_staff_without_content_permission_and_partners_cannot_add_reviews(): void
    {
        $payload = ['name' => 'Someone', 'rating' => 5, 'message' => 'A review typed by the wrong person.'];

        $this->actingAs(User::factory()->staff('account_manager')->create())->post('/commands/testimonials', $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'dealer']))->post('/commands/testimonials', $payload)->assertForbidden();

        $this->assertDatabaseCount('site_feedback', 0);
    }

    public function test_staff_added_review_refuses_html_tags(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->post('/commands/testimonials', [
            'name' => '<b>Bold name</b>',
            'rating' => 5,
            'message' => 'A perfectly normal review text here.',
        ])->assertSessionHasErrors('name');

        $this->assertDatabaseCount('site_feedback', 0);
    }

    public function test_home_page_invites_the_first_review_instead_of_showing_made_up_ones(): void
    {
        $this->withSession(['locale' => 'en'])->get('/')
            ->assertOk()
            ->assertSee('ref-testimonials-invite', false)
            ->assertSee(__('site.testimonials_invite_title', [], 'en'))
            ->assertSee('href="/feedback"', false)
            ->assertDontSee('ref-testimonials-section', false);

        $this->withSession(['locale' => 'bn'])->get('/')
            ->assertOk()
            ->assertSee(__('site.testimonials_invite_title', [], 'bn'));
    }

    public function test_invite_text_is_editable_and_the_switch_hides_the_whole_block(): void
    {
        SiteCustomization::save(['testimonials_invite_title' => 'Tell your neighbours about us']);

        $this->get('/')->assertOk()->assertSee('Tell your neighbours about us');

        SiteCustomization::save(['testimonials_section_enabled' => '0']);
        SiteFeedback::factory()->approved()->create(['name' => 'Hidden Person']);

        $this->get('/')->assertOk()
            ->assertDontSee('ref-testimonials-invite', false)
            ->assertDontSee('Hidden Person');
    }

    public function test_review_form_title_and_intro_come_from_the_website_editor(): void
    {
        $this->withSession(['locale' => 'bn'])->get('/feedback')
            ->assertOk()
            ->assertSee(__('site.feedback_page_title', [], 'bn'))
            ->assertSee(__('site.feedback_location_ph', [], 'bn'));

        SiteCustomization::save([
            'feedback_page_title' => 'Your words matter',
            'feedback_page_lead' => 'Write to us about the products you used.',
        ]);

        $this->get('/feedback')->assertOk()
            ->assertSee('Your words matter')
            ->assertSee('Write to us about the products you used.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validReview(): array
    {
        return [
            'name' => 'Public Guest',
            'role' => 'Teacher',
            'location' => 'Dhaka',
            'rating' => 5,
            'message' => 'Madina products helped my family stay healthy every season.',
        ];
    }
}
