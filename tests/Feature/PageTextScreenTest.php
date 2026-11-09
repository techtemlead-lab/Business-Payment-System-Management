<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PageTextScreenTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const PagesThatShowTheirText = ['contact', 'notice', 'circular-training', 'circular-price', 'circular-meeting', 'partnership', 'quality'];

    /** @var list<string> */
    private const PagesWithNowhereToShowIt = ['home', 'about', 'services', 'faq', 'privacy', 'terms', 'products', 'dealers'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    private function storePage(string $slug, string $title, string $body): void
    {
        DB::table('content_pages')->insert(['slug' => $slug, 'title' => $title, 'body' => $body, 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
        SiteCustomization::forgetCache();
    }

    public function test_the_screen_lists_only_pages_that_show_their_text(): void
    {
        foreach ([...self::PagesThatShowTheirText, ...self::PagesWithNowhereToShowIt] as $slug) {
            $this->storePage($slug, 'Title of '.$slug, 'Body of '.$slug);
        }

        $props = data_get($this->actingAs($this->owner())->onPortal('sadmin')->get('/content')->assertOk()->original->getData(), 'page.props.data');

        $this->assertEqualsCanonicalizing(self::PagesThatShowTheirText, collect($props['pages'])->pluck('slug')->all());
        $this->assertEqualsCanonicalizing(self::PagesThatShowTheirText, $props['page_slugs']);
    }

    public function test_a_page_with_nowhere_to_show_its_text_cannot_be_saved(): void
    {
        foreach (self::PagesWithNowhereToShowIt as $slug) {
            $this->actingAs($this->owner())->onPortal('sadmin')->post('/commands/content', [
                'slug' => $slug,
                'title' => 'Saved title',
                'body' => 'Saved body',
                'published' => true,
            ])->assertSessionHasErrors('slug');
        }

        $this->assertSame(0, DB::table('content_pages')->whereIn('slug', self::PagesWithNowhereToShowIt)->count());
    }

    public function test_text_left_over_from_a_removed_page_no_longer_reaches_the_site(): void
    {
        foreach (['about', 'services', 'faq', 'privacy', 'terms', 'dealers'] as $slug) {
            $this->storePage($slug, 'Leftover title '.$slug, 'Leftover body '.$slug);

            $this->get('/'.$slug)->assertOk()
                ->assertDontSee('Leftover title '.$slug)
                ->assertDontSee('Leftover body '.$slug);
        }
    }

    public function test_every_page_kept_on_the_screen_still_shows_its_text(): void
    {
        foreach (self::PagesThatShowTheirText as $slug) {
            $this->actingAs($this->owner())->onPortal('sadmin')->post('/commands/content', [
                'slug' => $slug,
                'title' => 'Kept title '.$slug,
                'body' => 'Kept body '.$slug,
                'published' => true,
            ])->assertRedirect()->assertSessionHas('success');

            $html = (string) $this->onPortal('public')->get('/'.$slug)->assertOk()->getContent();
            $visiblePage = substr($html, (int) stripos($html, '</head>'));

            $this->assertStringContainsString('Kept body '.$slug, $visiblePage);
        }
    }
}
