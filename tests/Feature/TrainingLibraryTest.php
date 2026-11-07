<?php

namespace Tests\Feature;

use App\Domain\Content\TrainingVideoLink;
use App\Models\TrainingItem;
use App\Models\User;
use Database\Seeders\TrainingItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrainingLibraryTest extends TestCase
{
    use RefreshDatabase;

    private const PdfBytes = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** The opening "ftyp" box of an MP4 file, enough for the server to recognise the type. */
    private const Mp4Bytes = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41\x00\x00\x00\x08free";

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_content_staff_can_add_edit_publish_reorder_and_delete_training(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [
            'title_bn' => 'অর্ডার দেওয়ার নিয়ম',
            'title_en' => 'How to order',
            'summary' => 'Four steps.',
            'type' => 'article',
            'topic' => 'portal',
            'audience' => 'all',
            'body' => 'Open Buy products and pick what you need.',
        ])->assertRedirect()->assertSessionHas('success');

        $first = TrainingItem::query()->sole();
        $this->assertFalse($first->published);
        $this->assertSame($editor->id, $first->created_by);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [
            'title_bn' => 'পণ্যের ভিডিও',
            'title_en' => 'Product video',
            'type' => 'video',
            'topic' => 'products',
            'audience' => 'dealers',
            'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $second = TrainingItem::query()->where('title_en', 'Product video')->sole();
        $this->assertGreaterThan($first->sort_order, $second->sort_order);

        $this->actingAs($editor)->onPortal('manage')->patch('/commands/training/'.$first->id, [
            'title_bn' => 'অর্ডার দেওয়ার নিয়ম',
            'title_en' => 'How to place an order',
            'type' => 'article',
            'topic' => 'portal',
            'audience' => 'sub_dealers',
            'body' => 'Updated steps.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('How to place an order', $first->fresh()->title_en);
        $this->assertSame('sub_dealers', $first->fresh()->audience);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/training/'.$first->id.'/publish', ['published' => true])->assertRedirect();
        $this->assertTrue($first->fresh()->published);
        $this->assertNotNull($first->fresh()->published_at);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/training/reorder', ['ids' => [$second->id, $first->id]])->assertRedirect();
        $this->assertSame([$second->id, $first->id], TrainingItem::query()->orderBy('sort_order')->pluck('id')->all());

        $this->actingAs($editor)->onPortal('manage')->get('/training')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'training')
                ->where('data.training.mode', 'staff')
                ->where('data.training.can_edit', true)
                ->has('data.training.items', 2)
                ->where('data.training.items.0.id', $second->id)
                ->where('data.training.items.0.embed_url', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'));

        $this->actingAs($editor)->onPortal('manage')->delete('/commands/training/'.$second->id)->assertRedirect();
        $this->assertDatabaseMissing('training_items', ['id' => $second->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'training.deleted', 'subject' => (string) $second->id]);
    }

    public function test_owner_without_content_permission_can_still_manage_training(): void
    {
        $managingDirector = User::factory()->staff('md')->create();

        $this->actingAs($managingDirector)->onPortal('sadmin')->get('/training')->assertOk();
        $this->actingAs($managingDirector)->onPortal('sadmin')->post('/commands/training', [
            'title_bn' => 'নিয়ম',
            'title_en' => 'Rules',
            'type' => 'article',
            'topic' => 'rules',
            'audience' => 'all',
            'body' => 'Never sell above MRP.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('training_items', ['title_en' => 'Rules', 'created_by' => $managingDirector->id]);
    }

    public function test_other_staff_and_partners_cannot_create_or_edit_training(): void
    {
        $item = TrainingItem::factory()->create();
        $payload = ['title_bn' => 'ক', 'title_en' => 'A', 'type' => 'article', 'topic' => 'rules', 'audience' => 'all', 'body' => 'Text'];

        foreach (['finance', 'inventory', 'account_manager'] as $role) {
            $staff = User::factory()->staff($role)->create();
            $this->actingAs($staff)->onPortal('manage')->post('/commands/training', $payload)->assertForbidden();
            $this->actingAs($staff)->onPortal('manage')->patch('/commands/training/'.$item->id, $payload)->assertForbidden();
            $this->actingAs($staff)->onPortal('manage')->post('/commands/training/'.$item->id.'/publish', ['published' => true])->assertForbidden();
            $this->actingAs($staff)->onPortal('manage')->delete('/commands/training/'.$item->id)->assertForbidden();
            $this->actingAs($staff)->onPortal('manage')->get('/training')->assertForbidden();
        }

        $dealer = User::factory()->dealer()->create();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/training', $payload)->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->patch('/commands/training/'.$item->id, $payload)->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/training/'.$item->id.'/publish', ['published' => true])->assertForbidden();

        $subDealer = User::factory()->subDealer()->create();
        $this->actingAs($subDealer)->onPortal('subdealer')->delete('/commands/training/'.$item->id)->assertForbidden();

        $this->assertDatabaseCount('training_items', 1);
        $this->assertFalse($item->fresh()->published);
    }

    public function test_partners_only_see_published_items_for_their_role_grouped_by_topic(): void
    {
        $forEveryone = TrainingItem::factory()->published()->create(['topic' => 'rules', 'title_en' => 'Everyone rules']);
        $forDealers = TrainingItem::factory()->published()->forAudience('dealers')->create(['topic' => 'products', 'title_en' => 'Dealer products']);
        $forSubDealers = TrainingItem::factory()->published()->forAudience('sub_dealers')->create(['topic' => 'selling', 'title_en' => 'Sub-dealer selling']);
        TrainingItem::factory()->create(['topic' => 'rules', 'title_en' => 'Draft rules']);
        TrainingItem::factory()->published()->create(['topic' => 'rules', 'title_en' => 'Old rules', 'published_at' => now()->subDays(20)]);

        $dealer = User::factory()->dealer()->create();
        $this->actingAs($dealer)->onPortal('dealer')->get('/training')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('data.training.mode', 'partner')
                ->has('data.training.groups', 2)
                ->where('data.training.groups.0.topic', 'products')
                ->where('data.training.groups.0.items.0.id', $forDealers->id)
                ->where('data.training.groups.1.topic', 'rules')
                ->has('data.training.groups.1.items', 2)
                ->where('data.training.groups.1.items', fn ($items) => collect($items)->pluck('title_en')->sort()->values()->all() === ['Everyone rules', 'Old rules'])
                ->where('data.training.groups.1.items', fn ($items) => collect($items)->firstWhere('title_en', 'Old rules')['is_new'] === false
                    && collect($items)->firstWhere('title_en', 'Everyone rules')['is_new'] === true)
                ->missing('data.training.groups.1.items.0.video_url'));

        $subDealer = User::factory()->subDealer()->create();
        $this->actingAs($subDealer)->onPortal('subdealer')->get('/training')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.training.groups', 2)
                ->where('data.training.groups.0.topic', 'selling')
                ->where('data.training.groups.0.items.0.id', $forSubDealers->id)
                ->where('data.training.groups.1.topic', 'rules')
                ->where('data.training.groups', fn ($groups) => ! collect($groups)->pluck('items')->flatten(1)->pluck('id')->contains($forDealers->id)));

        $this->assertTrue($forEveryone->isVisibleTo($dealer));
        $this->assertFalse($forSubDealers->isVisibleTo($dealer));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function videoLinks(): array
    {
        return [
            'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'youtube short link' => ['https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'youtube mobile shorts' => ['https://m.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'facebook video' => ['https://www.facebook.com/madina/videos/123456789', 'https://www.facebook.com/plugins/video.php?href='.rawurlencode('https://www.facebook.com/madina/videos/123456789').'&show_text=false'],
            'fb.watch' => ['https://fb.watch/abc123/', 'https://www.facebook.com/plugins/video.php?href='.rawurlencode('https://fb.watch/abc123').'&show_text=false'],
            'plain http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ', null],
            'other host' => ['https://vimeo.com/123456', null],
            'look-alike host' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ', null],
            'look-alike suffix' => ['https://notyoutube.com/watch?v=dQw4w9WgXcQ', null],
            'credentials in link' => ['https://user:pass@www.youtube.com/watch?v=dQw4w9WgXcQ', null],
            'odd port' => ['https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ', null],
            'youtube without a video' => ['https://www.youtube.com/channel/abc', null],
            'facebook home page' => ['https://www.facebook.com/', null],
            'javascript' => ['javascript:alert(1)', null],
            'quote injection' => ['https://www.facebook.com/x"onload="alert(1)', null],
        ];
    }

    #[DataProvider('videoLinks')]
    public function test_video_links_must_be_https_on_the_allowed_hosts(string $url, ?string $embed): void
    {
        $this->assertSame($embed, TrainingVideoLink::embedUrl($url));
    }

    public function test_saving_a_video_with_a_disallowed_link_is_rejected(): void
    {
        $editor = User::factory()->staff('editor')->create();
        $base = ['title_bn' => 'ভিডিও', 'title_en' => 'Video', 'type' => 'video', 'topic' => 'products', 'audience' => 'all'];

        foreach (['http://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://vimeo.com/123456', 'https://evil.example/fb.watch'] as $bad) {
            $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [...$base, 'video_url' => $bad])
                ->assertSessionHasErrors('video_url');
        }
        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', $base)->assertSessionHasErrors('video_url');
        $this->assertDatabaseCount('training_items', 0);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [...$base, 'video_url' => 'https://fb.watch/abc123/'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('training_items', ['video_url' => 'https://fb.watch/abc123/']);
    }

    public function test_pdf_guides_are_stored_privately_and_streamed_only_to_allowed_users(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $editor = User::factory()->staff('editor')->create();
        $base = ['title_bn' => 'গাইড', 'title_en' => 'Guide', 'type' => 'pdf', 'topic' => 'products', 'audience' => 'dealers'];

        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', $base)->assertSessionHasErrors('pdf');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [...$base, 'pdf' => $this->realUpload('notes.pdf', 'just some text, not a PDF')])
            ->assertSessionHasErrors('pdf');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [...$base, 'pdf' => UploadedFile::fake()->create('huge.pdf', 20481, 'application/pdf')])
            ->assertSessionHasErrors('pdf');
        $this->assertDatabaseCount('training_items', 0);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [...$base, 'pdf' => $this->realUpload('Product guide.pdf', self::PdfBytes)])
            ->assertSessionHasNoErrors();
        $item = TrainingItem::query()->sole();
        $this->assertStringStartsWith('training/', (string) $item->pdf_path);
        Storage::disk('local')->assertExists((string) $item->pdf_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame('Product-guide.pdf', $item->pdf_name);

        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create();
        $financeStaff = User::factory()->staff('finance')->create();

        $this->actingAs($dealer)->onPortal('dealer')->get('/training/'.$item->id.'/pdf')->assertNotFound();
        $this->actingAs($financeStaff)->onPortal('manage')->get('/training/'.$item->id.'/pdf')->assertNotFound();
        $this->actingAs($editor)->onPortal('manage')->get('/training/'.$item->id.'/pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($editor)->onPortal('manage')->post('/commands/training/'.$item->id.'/publish', ['published' => true]);

        $response = $this->actingAs($dealer)->onPortal('dealer')->get('/training/'.$item->id.'/pdf');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(self::PdfBytes, $response->streamedContent());

        $this->actingAs($financeStaff)->onPortal('manage')->get('/training/'.$item->id.'/pdf')->assertOk();
        $this->actingAs($subDealer)->onPortal('subdealer')->get('/training/'.$item->id.'/pdf')->assertNotFound();

        auth()->logout();
        $this->onPortal('dealer')->get('/training/'.$item->id.'/pdf')->assertRedirect();

        $oldPath = (string) $item->pdf_path;
        $this->actingAs($editor)->onPortal('manage')->patch('/commands/training/'.$item->id, [...$base, 'title_en' => 'Guide v2'])
            ->assertSessionHasNoErrors();
        $this->assertSame($oldPath, $item->fresh()->pdf_path, 'Editing without a new file keeps the old PDF.');

        $this->actingAs($editor)->onPortal('manage')->patch('/commands/training/'.$item->id, [...$base, 'type' => 'article', 'body' => 'Now an article.'])
            ->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->pdf_path);
        Storage::disk('local')->assertMissing($oldPath);
        $this->actingAs($dealer)->onPortal('dealer')->get('/training/'.$item->id.'/pdf')->assertNotFound();
    }

    public function test_staff_upload_a_video_file_that_partners_can_play_and_download(): void
    {
        Storage::fake('local');
        config(['madina.training.video_max_mb' => 5]);
        $editor = User::factory()->staff('editor')->create();
        $base = ['title_bn' => 'পণ্য পরিচিতি', 'title_en' => 'Product intro', 'type' => 'video', 'topic' => 'products', 'audience' => 'dealers'];

        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', $base)->assertSessionHasErrors('video_url');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [...$base, 'video_file' => $this->realUpload('clip.mp4', 'plain text pretending to be a video')])
            ->assertSessionHasErrors('video_file');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [...$base, 'video_file' => UploadedFile::fake()->create('clip.avi', 100, 'video/x-msvideo')])
            ->assertSessionHasErrors('video_file');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [...$base, 'video_file' => UploadedFile::fake()->create('long.mp4', 5 * 1024 + 1, 'video/mp4')])
            ->assertSessionHasErrors('video_file');
        $this->assertDatabaseCount('training_items', 0);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/training', [...$base, 'video_file' => $this->realUpload('Product intro.mp4', self::Mp4Bytes)])
            ->assertSessionHasNoErrors();
        $item = TrainingItem::query()->sole();
        $this->assertStringStartsWith('training/videos/', (string) $item->video_path);
        $this->assertNull($item->video_url);
        $this->assertSame('video/mp4', $item->video_mime);
        $this->assertSame('Product-intro.mp4', $item->video_name);
        Storage::disk('local')->assertExists((string) $item->video_path);

        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create();
        $this->actingAs($dealer)->onPortal('dealer')->get('/training/'.$item->id.'/video')->assertNotFound();
        $this->actingAs($editor)->onPortal('manage')->post('/commands/training/'.$item->id.'/publish', ['published' => true]);

        $played = $this->actingAs($dealer)->onPortal('dealer')->get('/training/'.$item->id.'/video');
        $played->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->assertStringContainsString('inline', (string) $played->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $played->headers->get('Cache-Control'));
        $this->assertSame(self::Mp4Bytes, $played->streamedContent());
        $saved = $this->actingAs($dealer)->onPortal('dealer')->get('/training/'.$item->id.'/video?download=1');
        $this->assertStringContainsString('attachment', (string) $saved->headers->get('Content-Disposition'));
        $this->actingAs($dealer)->onPortal('dealer')->withHeaders(['Range' => 'bytes=0-3'])->get('/training/'.$item->id.'/video')->assertStatus(206);
        $this->actingAs($subDealer)->onPortal('subdealer')->get('/training/'.$item->id.'/video')->assertNotFound();

        $this->actingAs($dealer)->onPortal('dealer')->get('/training')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.training.groups.0.items.0.video_file_url', '/training/'.$item->id.'/video')
                ->where('data.training.groups.0.items.0.video_download_url', '/training/'.$item->id.'/video?download=1')
                ->where('data.training.groups.0.items.0.embed_url', null)
                ->missing('data.training.groups.0.items.0.video_url'));

        $oldPath = (string) $item->video_path;
        $this->actingAs($editor)->onPortal('manage')->patch('/commands/training/'.$item->id, [...$base, 'title_en' => 'Product intro v2'])
            ->assertSessionHasNoErrors();
        $this->assertSame($oldPath, $item->fresh()->video_path, 'Editing without a new file keeps the uploaded video.');

        $this->actingAs($editor)->onPortal('manage')->patch('/commands/training/'.$item->id, [...$base, 'video_url' => 'https://youtu.be/dQw4w9WgXcQ'])
            ->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->video_path);
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $item->fresh()->video_url);
        Storage::disk('local')->assertMissing($oldPath);
        $this->actingAs($dealer)->onPortal('dealer')->get('/training/'.$item->id.'/video')->assertNotFound();
    }

    public function test_training_files_go_to_the_configured_disk_and_are_removed_with_the_item(): void
    {
        Storage::fake('local');
        Storage::fake('training');
        config(['madina.training.disk' => 'training']);
        $owner = User::factory()->staff('md')->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/training', [
            'title_bn' => 'ভিডিও', 'title_en' => 'Video', 'type' => 'video', 'topic' => 'selling', 'audience' => 'all',
            'video_file' => $this->realUpload('talk.mp4', self::Mp4Bytes),
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/training', [
            'title_bn' => 'গাইড', 'title_en' => 'Guide', 'type' => 'pdf', 'topic' => 'rules', 'audience' => 'all',
            'pdf' => $this->realUpload('rules.pdf', self::PdfBytes),
        ])->assertSessionHasNoErrors();

        $video = TrainingItem::query()->where('type', 'video')->sole();
        $guide = TrainingItem::query()->where('type', 'pdf')->sole();
        Storage::disk('training')->assertExists([(string) $video->video_path, (string) $guide->pdf_path]);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/training/'.$guide->id.'/publish', ['published' => true]);
        $dealer = User::factory()->dealer()->create();
        $download = $this->actingAs($dealer)->onPortal('dealer')->get('/training/'.$guide->id.'/pdf?download=1');
        $download->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame(self::PdfBytes, $download->streamedContent());

        $this->actingAs($owner)->onPortal('sadmin')->delete('/commands/training/'.$video->id)->assertRedirect();
        Storage::disk('training')->assertMissing((string) $video->video_path);
    }

    /**
     * An upload whose type is read from its bytes, like a real browser upload
     * (fake uploads report a type from the file name only).
     */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'training');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_starter_lessons_are_seeded_as_drafts_only_once(): void
    {
        $this->seed(TrainingItemSeeder::class);
        $this->seed(TrainingItemSeeder::class);

        $this->assertSame(3, TrainingItem::query()->count());
        $this->assertSame(0, TrainingItem::query()->where('published', true)->count());
        $this->assertSame(
            ['How to place an order and send the payment screenshot', 'MRP, your ID card and no false income promises', 'Using the team page'],
            TrainingItem::query()->orderBy('sort_order')->pluck('title_en')->all(),
        );

        $dealer = User::factory()->dealer()->create();
        $this->actingAs($dealer)->onPortal('dealer')->get('/training')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.training.groups', 0));
    }

    public function test_content_security_policy_allows_only_the_two_video_players(): void
    {
        $dealer = User::factory()->dealer()->create();
        $csp = (string) $this->actingAs($dealer)->onPortal('dealer')->get('/training')->headers->get('Content-Security-Policy');

        preg_match('/frame-src ([^;]+);/', $csp, $match);
        $this->assertNotEmpty($match);
        $this->assertStringContainsString('https://www.youtube-nocookie.com', $match[1]);
        $this->assertStringContainsString('https://www.facebook.com', $match[1]);
        $this->assertStringNotContainsString('https://www.youtube.com', $match[1]);
        $this->assertStringNotContainsString('*', str_replace('*.example.test', '', $match[1]));
    }
}
