<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\User;
use App\Services\WebsiteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebsiteCustomizationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/website/customize')->assertOk();

        return $admin;
    }

    private function document(): array
    {
        return app(WebsiteService::class)->defaults();
    }

    public function test_guests_and_dealers_cannot_edit_publish_or_preview_company_content(): void
    {
        $this->get('/website/customize')->assertRedirect('/login');
        $dealer = Dealer::factory()->create();
        $this->actingAs($dealer->user)->get('/website/customize')->assertForbidden();
        $this->get('/website/preview')->assertForbidden();
        $this->putJson('/website/draft', ['document' => $this->document(), 'revision' => 1])->assertForbidden();
        $this->post('/website/publish', ['revision' => 1, 'summary' => 'Unauthorized'])->assertForbidden();
        $this->postJson('/website/media')->assertForbidden();
        $this->post('/website/restore/1', ['revision' => 1])->assertForbidden();
        $this->delete('/website/schedule')->assertForbidden();
    }

    public function test_saved_draft_is_private_until_published_and_restore_does_not_publish(): void
    {
        $this->admin();
        $document = $this->document();
        $document['hero']['title'] = 'A company-specific welcome';
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->get('/website/preview')->assertInertia(fn (Assert $page) => $page->where('website.hero.title', 'A company-specific welcome')->where('preview', true));
        $this->get('/website/customize')->assertInertia(fn (Assert $page) => $page->where('website.hero.title', "Good business\nstarts with"));
        $this->post('/website/publish', ['revision' => 2, 'summary' => 'New hero'])->assertRedirect();
        $this->get('/website/customize')->assertInertia(fn (Assert $page) => $page->where('website.hero.title', 'A company-specific welcome'));
        $this->assertDatabaseHas('audit_events', ['action' => 'website.published']);
        $this->post('/website/restore/1', ['revision' => 2])->assertRedirect();
        $this->get('/website/preview')->assertInertia(fn (Assert $page) => $page->where('website.hero.title', "Good business\nstarts with"));
        $this->get('/website/customize')->assertInertia(fn (Assert $page) => $page->where('website.hero.title', 'A company-specific welcome')->where('revision', 3));
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_stale_drafts_cannot_overwrite_or_publish_another_admins_changes(): void
    {
        $this->admin();
        $document = $this->document();
        $document['brand']['name'] = 'First saved company';
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertOk();
        $document['brand']['name'] = 'Stale company';
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->post('/website/publish', ['revision' => 1, 'summary' => 'Stale publish'])->assertSessionHasErrors('revision');
        $this->get('/website/preview')->assertInertia(fn (Assert $page) => $page->where('website.brand.name', 'First saved company'));
    }

    public function test_unsafe_links_colors_translations_and_unknown_settings_are_rejected(): void
    {
        $this->admin();
        $document = $this->document();
        $document['hero']['button_href'] = 'javascript:alert(1)';
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('document.hero.button_href');
        $document = $this->document();
        $document['theme']['primary'] = 'red; background:url(https://example.test)';
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('document.theme.primary');
        $document = $this->document();
        $document['translations'] = ['hero.button_href' => 'javascript:alert(1)'];
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('document.translations');
        $document = $this->document();
        $document['commission_bps'] = 9000;
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertDatabaseCount('website_versions', 1);
    }

    public function test_required_application_fields_cannot_be_removed(): void
    {
        $this->admin();
        $document = $this->document();
        $document['application']['field_order'] = ['email'];
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('document.application.field_order');
    }

    public function test_bengali_translations_are_saved_without_changing_english_or_link_targets(): void
    {
        $this->admin();
        $document = $this->document();
        $document['translations'] = ['hero.title' => 'আপনার ব্যবসা', 'application.labels.name' => 'পূর্ণ নাম'];
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertOk();
        $stored = json_decode(DB::table('website_settings')->find(1)->draft, true);
        $this->assertSame('আপনার ব্যবসা', $stored['translations']['hero.title']);
        $this->assertSame('পূর্ণ নাম', $stored['translations']['application.labels.name']);
        $this->assertSame("Good business\nstarts with", $stored['hero']['title']);
        $this->assertSame('/login', $stored['hero']['button_href']);
    }

    public function test_pages_are_private_until_published_and_noindex_pages_are_excluded_from_sitemap(): void
    {
        $this->admin();
        $document = $this->document();
        $document['pages'] = [['slug' => 'about', 'title' => 'Our company', 'description' => 'Our story', 'visible' => true, 'index' => true, 'sections' => []], ['slug' => 'private', 'title' => 'Private', 'description' => '', 'visible' => false, 'index' => true, 'sections' => []], ['slug' => 'campaign', 'title' => 'Campaign', 'description' => '', 'visible' => true, 'index' => false, 'sections' => []]];
        $document['redirects'] = [['from' => 'old-about', 'to' => '/pages/about']];
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertOk();
        $this->get('/pages/about')->assertNotFound();
        $this->post('/website/publish', ['revision' => 2, 'summary' => 'Public pages'])->assertRedirect();
        $this->get('/pages/about')->assertOk()->assertInertia(fn (Assert $page) => $page->has('website.pages', 2)->where('website.pages.0.slug', 'about')->where('website.pages.1.slug', 'campaign'));
        $this->get('/pages/private')->assertNotFound();
        $this->get('/pages/old-about')->assertRedirect('/pages/about')->assertStatus(301);
        $this->get('/sitemap.xml')->assertSee('/pages/about')->assertDontSee('/pages/private')->assertDontSee('/pages/campaign');
    }

    public function test_scheduled_publication_uses_snapshot_and_can_be_cancelled(): void
    {
        $this->freezeTime();
        $this->admin();
        $document = $this->document();
        $document['hero']['title'] = 'Scheduled welcome';
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertOk();
        $this->post('/website/publish', ['revision' => 2, 'summary' => 'Schedule', 'publish_at' => now()->addHour()->toIso8601String()])->assertRedirect();
        $document['hero']['title'] = 'Later unscheduled draft';
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 2])->assertOk();
        $this->travel(61)->minutes();
        $this->get('/website/customize')->assertInertia(fn (Assert $page) => $page->where('website.hero.title', 'Scheduled welcome')->where('draft.hero.title', 'Later unscheduled draft'));
        $this->post('/website/publish', ['revision' => 3, 'summary' => 'Cancel me', 'publish_at' => now()->addHour()->toIso8601String()])->assertRedirect();
        $this->delete('/website/schedule')->assertRedirect();
        $this->assertDatabaseHas('website_settings', ['id' => 1, 'publish_at' => null, 'scheduled_document' => null]);
    }

    public function test_image_library_stores_only_valid_images_and_serves_correct_content_type(): void
    {
        Storage::fake('local');
        $this->admin();
        $this->postJson('/website/media', ['file' => UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=');
        $response = $this->postJson('/website/media', ['file' => UploadedFile::fake()->createWithContent('logo.png', $png), 'alt' => 'Company logo', 'folder' => 'Brand']);
        $response->assertCreated()->assertJsonPath('media.0.alt', 'Company logo');
        $row = DB::table('website_media')->first();
        Storage::disk('local')->assertExists($row->path);
        $this->get($response->json('media.0.url'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->patchJson('/website/media/'.$row->key, ['name' => 'Updated logo', 'alt' => 'New alt', 'folder' => 'Logos'])->assertOk();
        $this->assertDatabaseHas('website_media', ['key' => $row->key, 'alt' => 'New alt']);
    }

    public function test_application_questions_are_validated_and_snapshotted_for_company_review(): void
    {
        $admin = $this->admin();
        $document = $this->document();
        $document['application']['questions'] = [['id' => 'experience', 'label' => 'Years in business', 'type' => 'select', 'required' => true, 'options' => ['1–3', '4+'], 'when_field' => 'level', 'when_value' => 'District']];
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertOk();
        $this->post('/website/publish', ['revision' => 2, 'summary' => 'Application questions'])->assertRedirect();
        auth()->logout();
        $payload = ['name' => 'Applicant', 'email' => 'applicant@example.test', 'mobile' => '+8801712345678', 'business_name' => 'Business', 'business_address' => 'Dhaka', 'level' => 'District', 'division' => 'Dhaka', 'district' => 'Dhaka', 'consent' => true];
        $this->post('/request-dealership', $payload)->assertSessionHasErrors('additional_answers.experience');
        $this->post('/request-dealership', [...$payload, 'additional_answers' => ['experience' => 'invented']])->assertSessionHasErrors('additional_answers.experience');
        $this->post('/request-dealership', [...$payload, 'additional_answers' => ['experience' => '4+']])->assertRedirect('/request-dealership');
        $this->actingAs($admin)->get('/dealership-requests')->assertInertia(fn (Assert $page) => $page->where('requests.data.0.additional_answers.0.label', 'Years in business')->where('requests.data.0.additional_answers.0.value', '4+'));
        $this->assertDatabaseCount('dealers', 0);
    }

    public function test_closed_applications_are_rejected_by_server_even_if_form_is_bypassed(): void
    {
        $this->admin();
        $document = $this->document();
        $document['application']['open'] = false;
        $this->putJson('/website/draft', ['document' => $document, 'revision' => 1])->assertOk();
        $this->post('/website/publish', ['revision' => 2, 'summary' => 'Close applications'])->assertRedirect();
        auth()->logout();
        $this->post('/request-dealership', [])->assertForbidden();
        $this->assertDatabaseCount('dealership_requests', 0);
    }
}
