<?php

namespace Tests\Feature;

use App\Models\SiteFeedback;
use App\Models\User;
use App\Support\CategoryAutoIcon;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_category_auto_icon_maps_ayurvedic_to_leaf(): void
    {
        $icon = CategoryAutoIcon::forCategory('Ayurvedic');
        $this->assertSame('leaf', $icon['key']);
    }

    public function test_public_can_submit_feedback_and_staff_can_approve_for_homepage(): void
    {
        $this->get('/feedback')->assertOk()->assertSee(__('site.feedback_page_title'));

        $this->post('/feedback', [
            'name' => 'Public Guest',
            'role' => 'Wellness Client',
            'location' => 'Dhaka',
            'rating' => 5,
            'message' => 'Madina products helped my family stay healthy every season.',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('site_feedback', [
            'name' => 'Public Guest',
            'status' => 'pending',
            'source' => 'public',
        ]);

        $editor = User::factory()->create(['role' => 'editor']);
        $feedback = SiteFeedback::query()->firstOrFail();

        $this->actingAs($editor)->post('/commands/feedback/'.$feedback->id, [
            'status' => 'approved',
        ])->assertSessionHas('success');

        $feedback->refresh();
        $this->assertTrue($feedback->isApproved());

        $testimonials = SiteCustomization::getTestimonials();
        $this->assertNotEmpty($testimonials);
        $this->assertSame('Public Guest', $testimonials[0]['name']);

        $home = $this->get('/');
        $home->assertOk();
        $home->assertSee('Public Guest');
        $home->assertSee('Madina products helped my family stay healthy every season.');
        $home->assertSee('ref-dealer-banner-glyph', false);
        $home->assertSee('ref-chat-icon', false);
        $home->assertSee('ref-cat-auto-icon', false);
    }

    public function test_dealer_can_submit_feedback_from_partner_command(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'district' => 'Rangpur']);

        $this->actingAs($dealer)->post('/commands/feedback', [
            'rating' => 5,
            'location' => 'Rangpur',
            'message' => 'Our local clients trust Madina herbal stock and delivery support.',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('site_feedback', [
            'user_id' => $dealer->id,
            'source' => 'dealer',
            'status' => 'pending',
        ]);
    }
}
