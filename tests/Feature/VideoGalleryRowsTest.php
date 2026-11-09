<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class VideoGalleryRowsTest extends TestCase
{
    use RefreshDatabase;

    private const First = 'https://www.youtube.com/watch?v=aaaaaaaaaaa';

    private const Second = 'https://youtu.be/bbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    private function inLanguage(string $locale, string $path = '/'): TestResponse
    {
        auth()->forgetGuards();

        return $this->onPortal('public')->withSession(['locale' => $locale])->get($path);
    }

    /** @param  array<string, mixed>  $customization */
    private function saveInEditor(array $customization): TestResponse
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        return $this->actingAs($owner)->onPortal('sadmin')->post('/commands/customization', ['customization' => $customization]);
    }

    /** @return array<string, string> */
    private function row(string $link, string $title = 'Video title'): array
    {
        return ['title' => $title, 'title_bn' => '', 'subtitle' => '', 'subtitle_bn' => '', 'video_url' => $link];
    }

    public function test_a_row_added_in_the_editor_shows_on_the_home_page(): void
    {
        $this->saveInEditor([
            'video_gallery_enabled' => '1',
            'video_gallery_items' => [[
                'title' => 'Factory tour',
                'title_bn' => 'কারখানা ঘুরে দেখা',
                'subtitle' => 'How we work',
                'subtitle_bn' => 'আমরা কীভাবে কাজ করি',
                'video_url' => self::First,
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->inLanguage('en')->assertOk()
            ->assertSee('https://www.youtube-nocookie.com/embed/aaaaaaaaaaa', false)
            ->assertSee('Factory tour')
            ->assertSee('How we work')
            ->assertDontSee('কারখানা ঘুরে দেখা');
        $this->inLanguage('bn')->assertOk()
            ->assertSee('https://www.youtube-nocookie.com/embed/aaaaaaaaaaa', false)
            ->assertSee('কারখানা ঘুরে দেখা')
            ->assertSee('আমরা কীভাবে কাজ করি')
            ->assertDontSee('Factory tour');
    }

    public function test_rows_show_in_the_order_they_were_saved_and_can_be_edited_and_removed(): void
    {
        $this->saveInEditor(['video_gallery_enabled' => '1', 'video_gallery_items' => [$this->row(self::First, 'First'), $this->row(self::Second, 'Second')]])->assertSessionHasNoErrors();
        $this->inLanguage('en')->assertSeeInOrder(['embed/aaaaaaaaaaa', 'embed/bbbbbbbbbbb'], false);

        $this->saveInEditor(['video_gallery_items' => [$this->row(self::Second, 'Second'), $this->row(self::First, 'First')]])->assertSessionHasNoErrors();
        $this->inLanguage('en')->assertSeeInOrder(['embed/bbbbbbbbbbb', 'embed/aaaaaaaaaaa'], false);

        $this->saveInEditor(['video_gallery_items' => [$this->row(self::Second, 'Second, reworded')]])->assertSessionHasNoErrors();
        $this->inLanguage('en')->assertSee('Second, reworded')->assertDontSee('embed/aaaaaaaaaaa', false);
    }

    public function test_a_facebook_video_is_accepted(): void
    {
        $this->saveInEditor(['video_gallery_enabled' => '1', 'video_gallery_items' => [$this->row('https://www.facebook.com/madina/videos/1234567890')]])->assertSessionHasNoErrors();

        $this->inLanguage('en')->assertSee('https://www.facebook.com/plugins/video.php', false);
    }

    public function test_the_section_is_hidden_when_switched_off_or_empty(): void
    {
        $this->saveInEditor(['video_gallery_enabled' => '0', 'video_gallery_items' => [$this->row(self::First)]])->assertSessionHasNoErrors();
        $this->inLanguage('en')->assertOk()->assertDontSee('ref-video-gallery-section');

        $this->saveInEditor(['video_gallery_enabled' => '1', 'video_gallery_items' => []])->assertSessionHasNoErrors();
        $this->inLanguage('en')->assertOk()->assertDontSee('ref-video-gallery-section');
    }

    public function test_a_link_the_site_cannot_play_is_refused_with_its_box_named(): void
    {
        foreach (['https://vimeo.com/123456789', 'https://example.test/promo.mp4', 'not a link'] as $link) {
            $this->saveInEditor(['video_gallery_enabled' => '1', 'video_gallery_items' => [$this->row(self::First), $this->row($link)]])
                ->assertSessionHasErrors(['customization.video_gallery_items.1.video_url']);
        }

        $this->assertArrayNotHasKey('video_gallery_items', SiteCustomization::raw());
    }

    public function test_a_row_with_words_but_no_link_is_refused(): void
    {
        $this->saveInEditor(['video_gallery_items' => [$this->row('', 'Forgot the link')]])
            ->assertSessionHasErrors(['customization.video_gallery_items.0.video_url' => __('portal.sce_video_link_missing')]);
    }

    public function test_a_row_left_completely_empty_is_dropped(): void
    {
        $this->saveInEditor(['video_gallery_enabled' => '1', 'video_gallery_items' => [$this->row('', ''), $this->row(self::First)]])->assertSessionHasNoErrors();

        $this->assertCount(1, SiteCustomization::raw()['video_gallery_items']);
    }

    public function test_the_player_address_comes_only_from_the_checked_link(): void
    {
        $this->saveInEditor([
            'video_gallery_enabled' => '1',
            'video_gallery_items' => [[...$this->row(self::First), 'video_embed_url' => 'https://evil.example.test/frame']],
        ])->assertSessionHasNoErrors();

        $this->inLanguage('en')->assertDontSee('evil.example.test', false)->assertSee('embed/aaaaaaaaaaa', false);
    }

    public function test_the_empty_rows_older_saves_stored_do_not_show(): void
    {
        DB::table('content_pages')->updateOrInsert(['slug' => 'frontend_customization'], [
            'title' => 'Frontend Customization',
            'body' => json_encode([
                'video_gallery_enabled' => '1',
                'video_gallery_items' => [
                    ['title' => 'Herbal Care Guidance', 'subtitle' => 'Application guidance', 'video_url' => '', 'video_embed_url' => '', 'thumbnail' => '/branding/madina-top-skincare.jpg'],
                ],
            ]),
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SiteCustomization::forgetCache();

        $this->inLanguage('en')->assertOk()->assertDontSee('Herbal Care Guidance')->assertDontSee('ref-video-gallery-section');
    }

    public function test_a_save_that_does_not_mention_videos_keeps_them(): void
    {
        $this->saveInEditor(['video_gallery_enabled' => '1', 'video_gallery_items' => [$this->row(self::First, 'Keep me')]])->assertSessionHasNoErrors();

        SiteCustomization::save(['contact_title' => 'Another edit']);

        $this->inLanguage('en')->assertSee('Keep me');
    }
}
