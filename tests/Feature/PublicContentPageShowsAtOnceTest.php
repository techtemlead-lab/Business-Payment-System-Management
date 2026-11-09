<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicContentPageShowsAtOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    public function test_a_saved_page_reaches_the_public_site_without_waiting(): void
    {
        $editor = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->onPortal('public')->get('/notice')->assertOk()->assertDontSee('QA Office Notice');

        $this->actingAs($editor)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'notice',
            'title' => 'QA Office Notice',
            'body' => 'The office is closed on Friday.',
            'published' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $this->onPortal('public')->get('/notice')
            ->assertOk()
            ->assertSee('QA Office Notice')
            ->assertSee('The office is closed on Friday.');
    }

    public function test_a_page_put_back_to_draft_leaves_the_public_site_at_once(): void
    {
        $editor = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($editor)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'notice',
            'title' => 'QA Office Notice',
            'body' => 'The office is closed on Friday.',
            'published' => true,
        ])->assertRedirect();
        $this->onPortal('public')->get('/notice')->assertOk()->assertSee('The office is closed on Friday.');

        $this->actingAs($editor)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'notice',
            'title' => 'QA Office Notice',
            'body' => 'The office is closed on Friday.',
            'published' => false,
        ])->assertRedirect();

        $this->onPortal('public')->get('/notice')->assertOk()->assertDontSee('The office is closed on Friday.');
    }
}
