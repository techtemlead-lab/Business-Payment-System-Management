<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Managing Director's message on the About us page and the home page:
 * built-in text in each language, the owner's on/off switch, and editing the
 * text and photo in the website editor.
 */
class ManagingDirectorMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // The settings are kept in memory for one request; start each test clean.
        SiteCustomization::forgetCache();
    }

    public function test_about_and_home_pages_show_the_built_in_message_in_each_language(): void
    {
        foreach (['/about', '/'] as $path) {
            $this->withSession(['locale' => 'en'])->get($path)->assertOk()
                ->assertSee('id="md-message"', false)
                ->assertSee(__('site.md_message_heading', [], 'en'))
                ->assertSee('Mohammad Anwar Hossain')
                ->assertSee('Managing Director')
                ->assertSee('In the Name of Allah, the Most Gracious, the Most Merciful.')
                ->assertSee('We humbly ask for everyone\'s prayers and support.')
                ->assertSee(__('site.md_message_read_more', [], 'en'))
                ->assertSee('/branding/md-message/managing-director.webp', false)
                ->assertSee('alt="Photo of Mohammad Anwar Hossain, Managing Director, Madina Natural Health Care"', false);

            $this->withSession(['locale' => 'bn'])->get($path)->assertOk()
                ->assertSee('ব্যবস্থাপনা পরিচালকের বাণী')
                ->assertSee('মোহাম্মদ আনোয়ার হোসেন')
                ->assertSee('বিসমিল্লাহির রাহমানির রাহিম।')
                ->assertSee('সবার দোয়া ও সহযোগিতা কামনা করছি।')
                ->assertSee(__('site.md_message_read_more', [], 'bn'))
                ->assertDontSee(__('site.md_message_heading', [], 'en'))
                ->assertDontSee('In the Name of Allah, the Most Gracious, the Most Merciful.');
        }
    }

    public function test_the_message_shows_on_a_site_saved_before_it_existed(): void
    {
        // An existing website whose saved settings have no MD message boxes yet.
        DB::table('content_pages')->updateOrInsert(
            ['slug' => 'frontend_customization'],
            ['title' => 'Frontend Customization', 'body' => json_encode(['announcement_text' => 'Since 2019']), 'published' => true, 'updated_at' => now()],
        );
        SiteCustomization::forgetCache();

        $this->withSession(['locale' => 'en'])->get('/about')->assertOk()
            ->assertSee('id="md-message"', false)
            ->assertSee('Mohammad Anwar Hossain');
        $this->withSession(['locale' => 'en'])->get('/')->assertOk()->assertSee('Since 2019');
    }

    public function test_the_message_is_hidden_when_the_owner_switches_it_off(): void
    {
        SiteCustomization::save(['md_message_enabled' => '0']);

        foreach (['/about', '/'] as $path) {
            foreach (['en', 'bn'] as $locale) {
                $this->withSession(['locale' => $locale])->get($path)->assertOk()
                    ->assertDontSee('id="md-message"', false)
                    ->assertDontSee(__('site.md_message_heading', [], $locale));
            }
        }
    }

    public function test_the_owner_edits_the_message_and_its_bangla_boxes(): void
    {
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)->post('/commands/customization', ['customization' => [
            'md_message_name' => 'Md Anowar Hossain',
            'md_message_body' => "First paragraph from the owner.\n\nSecond paragraph from the owner.",
            'md_message_body_bn' => "মালিকের প্রথম অনুচ্ছেদ।\n\nমালিকের দ্বিতীয় অনুচ্ছেদ।",
            'md_message_image' => '/branding/site-photos/new-md.webp',
        ]])->assertSessionHasNoErrors()->assertSessionHas('success');

        // The owner signs out: a visitor reads the page in the language they picked.
        $this->app['auth']->forgetGuards();

        $this->withSession(['locale' => 'en'])->get('/about')->assertOk()
            ->assertSee('Md Anowar Hossain')
            ->assertSee('<p>First paragraph from the owner.</p>', false)
            ->assertSee('<p>Second paragraph from the owner.</p>', false)
            ->assertSee('/branding/site-photos/new-md.webp', false)
            ->assertDontSee('মালিকের প্রথম অনুচ্ছেদ।')
            // A short letter needs no "Read the full message" button.
            ->assertDontSee('data-md-toggle', false);

        $this->withSession(['locale' => 'bn'])->get('/about')->assertOk()
            ->assertSee('<p>মালিকের প্রথম অনুচ্ছেদ।</p>', false)
            ->assertDontSee('First paragraph from the owner.')
            // The Bangla name box is empty, so the owner's English edit is shown as written.
            ->assertSee('Md Anowar Hossain');

        $this->actingAs($owner)->get('/company/website')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.customization.md_message_name', 'Md Anowar Hossain')
                ->where('data.defaults.md_message_image', '/branding/md-message/managing-director.webp')
                ->where('data.bangla_defaults.md_message_body', __('site.md_message_body', [], 'bn')));
    }

    public function test_only_website_staff_can_change_the_message(): void
    {
        $payload = ['customization' => ['md_message_enabled' => '0']];

        $this->actingAs(User::factory()->staff('account_manager')->create())
            ->post('/commands/customization', $payload)->assertForbidden();
        $this->actingAs(User::factory()->dealer()->create())
            ->post('/commands/customization', $payload)->assertForbidden();

        $this->assertNotNull(SiteCustomization::managingDirectorMessage());
    }

    public function test_an_unsafe_photo_link_is_refused(): void
    {
        $this->actingAs(User::factory()->staff('editor')->create())
            ->post('/commands/customization', ['customization' => ['md_message_image' => 'javascript:alert(1)']])
            ->assertSessionHasErrors('customization.md_message_image');

        $this->assertSame('/branding/md-message/managing-director.webp', SiteCustomization::all()['md_message_image']);
    }

    public function test_the_owner_can_upload_a_new_photo(): void
    {
        $response = $this->actingAs(User::factory()->staff('editor')->create())
            ->post('/commands/customization/category-icon', [
                'image' => UploadedFile::fake()->image('md.jpg', 800, 1000),
                'purpose' => 'site_photo',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $url = (string) $response->json('url');
        $this->assertStringStartsWith('/branding/site-photos/', $url);
        $this->assertFileExists(public_path($url));
        @unlink(public_path($url));
    }

    public function test_the_built_in_photo_is_a_small_web_picture(): void
    {
        $path = public_path(SiteCustomization::defaults()['md_message_image']);

        $this->assertFileExists($path);
        $this->assertLessThan(150 * 1024, filesize($path));
        $this->assertSame(800, getimagesize($path)[0]);
    }
}
