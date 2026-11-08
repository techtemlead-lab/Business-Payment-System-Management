<?php

namespace Tests\Feature;

use App\Domain\Accounts\DealerVerification;
use App\Domain\Content\PublicTrustContent;
use App\Models\HealthArticle;
use App\Models\PlaceCode;
use App\Models\QualityDocument;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicTrustPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        SiteCustomization::forgetCache();
    }

    private function publicGet(string $path): TestResponse
    {
        return $this->onPortal('public')->get($path);
    }

    private function publicGetInBangla(string $path): TestResponse
    {
        $this->onPortal('public')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        return $this->onPortal('public')->get($path);
    }

    /** @param  array<string, mixed>  $attributes */
    private function dealerAt(?PlaceCode $place, array $attributes = []): User
    {
        return User::factory()->dealer()->create([
            'status' => 'active',
            'phone' => '01712345678',
            'district' => 'Free Typed District',
            'profile' => $place ? ['place_code_id' => $place->id, 'place_code' => $place->code] : [],
            ...$attributes,
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    /** @param  array<string, string>  $values */
    private function saveTrustText(array $values): TestResponse
    {
        return $this->actingAs($this->owner())->onPortal('sadmin')
            ->post('/commands/public-trust/text', ['values' => $values]);
    }

    public function test_verify_page_shows_only_the_allowed_facts_for_a_valid_dealer(): void
    {
        $place = PlaceCode::factory()->create(['code' => 'MNHC-7701', 'name' => 'Pirganj']);
        $dealer = $this->dealerAt($place, ['name' => 'Rahim Traders', 'dealer_code' => 'MD-1001', 'email' => 'rahim@example.test']);

        $response = $this->publicGet('/verify-dealer?code='.strtolower((string) $dealer->dealer_code));

        $response->assertOk()
            ->assertSee(__('public_trust.verify_valid'))
            ->assertSee('Rahim Traders')
            ->assertSee(__('public_trust.verify_type_dealer'))
            ->assertSee('Pirganj (MNHC-7701)')
            ->assertSee(__('public_trust.verify_status_active'))
            ->assertDontSee('01712345678')
            ->assertDontSee('rahim@example.test')
            ->assertDontSee('Free Typed District');
    }

    public function test_verify_page_accepts_the_referral_code_and_labels_sub_dealers(): void
    {
        User::factory()->subDealer()->create(['status' => 'active', 'name' => 'Karim Store', 'refer_code' => 'REF-55']);

        $this->publicGet('/verify-dealer?code=ref-55')
            ->assertOk()
            ->assertSee('Karim Store')
            ->assertSee(__('public_trust.verify_type_sub_dealer'))
            ->assertSee(__('public_trust.verify_area_not_set'));
    }

    public function test_verify_page_says_not_valid_for_an_unknown_code(): void
    {
        $this->publicGet('/verify-dealer?code=NOPE-404')
            ->assertOk()
            ->assertSee(__('public_trust.verify_not_valid'))
            ->assertSee(__('public_trust.verify_not_found'));
    }

    public function test_verify_page_never_confirms_a_staff_account(): void
    {
        User::factory()->create(['role' => 'finance', 'status' => 'active', 'dealer_code' => 'STAFF-1', 'name' => 'Office Person']);

        $this->publicGet('/verify-dealer?code=STAFF-1')
            ->assertOk()
            ->assertDontSee('Office Person')
            ->assertSee(__('public_trust.verify_not_found'));
    }

    public function test_verify_page_marks_a_suspended_partner_as_not_valid(): void
    {
        $this->dealerAt(null, ['name' => 'Stopped Dealer', 'dealer_code' => 'MD-2002', 'status' => 'suspended']);

        $this->publicGet('/verify-dealer?code=MD-2002')
            ->assertOk()
            ->assertSee(__('public_trust.verify_not_valid'))
            ->assertSee(__('public_trust.verify_status_suspended'))
            ->assertSee('Stopped Dealer')
            ->assertDontSee(__('public_trust.verify_valid').'</b>', false);
    }

    public function test_verify_page_is_rate_limited(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->publicGet('/verify-dealer?code=TRY-'.$attempt)->assertOk();
        }

        $this->publicGet('/verify-dealer?code=TRY-21')->assertStatus(429);
    }

    public function test_verify_page_carries_the_fake_dealer_warning_and_owner_can_change_it(): void
    {
        $this->publicGet('/verify-dealer')->assertOk()->assertSee(__('public_trust.verify_warning'));

        $this->saveTrustText(['trust_verify_warning' => 'Never pay in cash.'])->assertSessionHas('success');

        $this->publicGet('/verify-dealer')->assertOk()->assertSee('Never pay in cash.');
    }

    public function test_id_card_address_opens_the_verify_page_for_that_code(): void
    {
        $this->assertSame('/verify-dealer?code=MD-1%2F2', DealerVerification::path('MD-1/2'));
    }

    public function test_policy_and_conduct_drafts_exist_in_both_languages_without_stated_legal_periods(): void
    {
        foreach (['refund', 'delivery', 'conduct'] as $key) {
            foreach (['en', 'bn'] as $locale) {
                $body = trans('public_trust.'.$key.'_body', [], $locale);
                $this->assertNotSame('public_trust.'.$key.'_body', $body);
                $this->assertDoesNotMatchRegularExpression('/\b\d+\s*(day|days|hour|hours|month|months)\b|[০-৯]+\s*(দিন|ঘণ্টা|মাস)/u', $body);
            }
        }

        $this->publicGet('/refund-policy')
            ->assertOk()
            ->assertSee(__('public_trust.refund_title', [], 'en'))
            ->assertSee('a refund cannot be made through the website');
        $this->publicGet('/delivery-policy')->assertOk()->assertSee(__('public_trust.delivery_title', [], 'en'));
        $this->publicGet('/code-of-conduct')->assertOk()->assertSee(__('public_trust.conduct_title', [], 'en'));
        $this->publicGetInBangla('/refund-policy')->assertOk()->assertSee('ফেরত ও রিফান্ড নীতি');
    }

    public function test_no_default_text_claims_a_cure_or_promises_income(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $text = implode(' ', array_map(
                fn (string $key): string => (string) trans('public_trust.'.$key, [], $locale),
                ['articles_lead', 'articles_disclaimer', 'quality_documents_lead', 'verify_lead'],
            ));
            $this->assertDoesNotMatchRegularExpression('/\bcure|সারায়|guarante|গ্যারান্টি/ui', $text);
        }
    }

    public function test_owner_text_is_escaped_on_the_policy_page(): void
    {
        $this->saveTrustText(['trust_refund_body' => '<script>alert(1)</script> Owner policy words'])->assertRedirect();

        $this->publicGet('/refund-policy')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Owner policy words');
    }

    public function test_bangla_box_is_shown_to_bangla_visitors_only(): void
    {
        $this->saveTrustText([
            'trust_delivery_body' => 'English delivery words',
            'trust_delivery_body_bn' => 'বাংলা ডেলিভারির কথা',
        ])->assertRedirect();

        $this->publicGet('/delivery-policy')->assertSee('English delivery words');
        $this->publicGetInBangla('/delivery-policy')->assertSee('বাংলা ডেলিভারির কথা')->assertDontSee('English delivery words');
    }

    public function test_a_page_switched_off_is_gone_and_loses_its_footer_link(): void
    {
        $this->publicGet('/')->assertOk()->assertSee('href="/refund-policy"', false);

        $this->saveTrustText(['page_enabled_refund-policy' => '0'])->assertRedirect();

        $this->publicGet('/refund-policy')->assertNotFound();
        $this->publicGet('/')->assertOk()->assertDontSee('href="/refund-policy"', false);
    }

    public function test_partner_exit_page_ships_blank_and_hidden_with_no_default_terms(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $this->assertSame('', trans('public_trust.exit_body', [], $locale));
            $this->assertSame('', trans('public_trust.exit_lead', [], $locale));
        }

        $this->publicGet('/partner-exit-policy')->assertNotFound();
        $this->publicGet('/')->assertOk()->assertDontSee('href="/partner-exit-policy"', false);
    }

    public function test_partner_exit_page_appears_once_the_owner_fills_it_in(): void
    {
        $this->saveTrustText([
            'trust_exit_body' => '<b>Lawyer wording</b> in English',
            'trust_exit_body_bn' => 'আইনজীবীর লেখা বাংলা',
        ])->assertRedirect();

        $this->publicGet('/partner-exit-policy')
            ->assertOk()
            ->assertSee('&lt;b&gt;Lawyer wording&lt;/b&gt; in English', false)
            ->assertDontSee('<b>Lawyer wording</b>', false);
        $this->publicGet('/')->assertOk()->assertSee('href="/partner-exit-policy"', false);
        $this->publicGetInBangla('/partner-exit-policy')->assertOk()->assertSee('আইনজীবীর লেখা বাংলা');
    }

    public function test_business_identity_block_shows_only_filled_lines(): void
    {
        $this->publicGet('/')->assertOk()->assertDontSee(__('public_trust.identity_heading'));

        $this->saveTrustText(['trust_tin' => '123456789012', 'trust_trade_licence' => '<b>TL-9</b>'])->assertRedirect();

        $this->publicGet('/')
            ->assertOk()
            ->assertSee(__('public_trust.identity_heading'))
            ->assertSee(__('public_trust.identity_tin').': 123456789012')
            ->assertSee('&lt;b&gt;TL-9&lt;/b&gt;', false)
            ->assertDontSee(__('public_trust.identity_digital_business_id'))
            ->assertDontSee(__('public_trust.identity_joint_stock_registration'));
    }

    public function test_quality_documents_page_is_hidden_when_empty(): void
    {
        $this->publicGet('/quality-documents')->assertNotFound();
        $this->publicGet('/')->assertOk()->assertDontSee('href="/quality-documents"', false);

        QualityDocument::factory()->hidden()->create();

        $this->publicGet('/quality-documents')->assertNotFound();
        $this->publicGet('/')->assertOk()->assertDontSee('href="/quality-documents"', false);
    }

    public function test_owner_uploads_a_quality_document_and_the_public_can_open_it(): void
    {
        Storage::fake('local');

        $this->actingAs($this->owner())->onPortal('sadmin')
            ->post('/commands/public-trust/documents', [
                'title' => 'Trade licence 2026',
                'document' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $document = QualityDocument::query()->sole();
        Storage::disk('local')->assertExists($document->file_path);

        $this->publicGet('/quality-documents')->assertOk()->assertSee('Trade licence 2026');
        $this->publicGet('/')->assertOk()->assertSee('href="/quality-documents"', false);
        $this->publicGet('/quality-documents/'.$document->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hidden_quality_document_cannot_be_opened(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('quality-documents/hidden.pdf', 'pdf');
        $document = QualityDocument::factory()->hidden()->create(['file_path' => 'quality-documents/hidden.pdf']);

        $this->publicGet('/quality-documents/'.$document->id)->assertNotFound();
    }

    public function test_quality_document_upload_rejects_a_web_page_and_delete_removes_the_file(): void
    {
        Storage::fake('local');
        $owner = $this->owner();

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/public-trust/documents', [
                'title' => 'Bad file',
                'document' => UploadedFile::fake()->create('page.html', 2, 'text/html'),
            ])
            ->assertSessionHasErrors('document');
        $this->assertSame(0, QualityDocument::query()->count());

        Storage::disk('local')->put('quality-documents/one.pdf', 'pdf');
        $document = QualityDocument::factory()->create(['file_path' => 'quality-documents/one.pdf']);

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/public-trust/documents/'.$document->id.'/delete')
            ->assertRedirect();

        $this->assertDatabaseMissing('quality_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing('quality-documents/one.pdf');
    }

    public function test_complaint_gets_a_tracking_number_and_reaches_the_office_inbox(): void
    {
        $response = $this->onPortal('public')->from('/grievance')->post('/grievance', [
            'name' => 'Sokhina Begum',
            'phone' => '01811112222',
            'topic' => 'complaint',
            'message' => 'The dealer in my area took money and did not give goods.',
        ]);

        $response->assertRedirect('/grievance')->assertSessionHas('grievance_tracking');
        $trackingNumber = (string) session('grievance_tracking');
        $this->assertMatchesRegularExpression('/^GRV-\d{6}-[2-9A-HJKMNP-Z]{6}$/', $trackingNumber);
        $this->assertDatabaseHas('enquiries', ['tracking_number' => $trackingNumber, 'topic' => 'complaint', 'status' => 'new']);

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/enquiries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.enquiries.data', 1)
                ->where('data.enquiries.data.0.tracking_number', $trackingNumber));
    }

    public function test_product_problem_report_keeps_batch_number_and_needs_a_product_name(): void
    {
        $this->onPortal('public')->from('/grievance')->post('/grievance', [
            'name' => 'Sokhina Begum',
            'phone' => '01811112222',
            'topic' => 'product_problem',
            'batch_number' => 'B-77',
            'message' => 'I felt dizzy after using this product.',
        ])->assertSessionHasErrors('product_name');
        $this->assertSame(0, DB::table('enquiries')->count());

        $this->onPortal('public')->from('/grievance')->post('/grievance', [
            'name' => 'Sokhina Begum',
            'phone' => '01811112222',
            'topic' => 'product_problem',
            'product_name' => 'Herbal Syrup',
            'batch_number' => 'B-77',
            'message' => 'I felt dizzy after using this product.',
        ])->assertRedirect('/grievance');

        $this->assertDatabaseHas('enquiries', ['topic' => 'product_problem', 'product_name' => 'Herbal Syrup', 'batch_number' => 'B-77']);

        $this->actingAs(User::factory()->create(['role' => 'editor', 'status' => 'active']))->onPortal('manage')->get('/enquiries?q=B-77')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.enquiries.data.0.batch_number', 'B-77')
                ->where('data.enquiries.data.0.product_name', 'Herbal Syrup'));
    }

    public function test_grievance_form_rejects_bots_and_is_rate_limited(): void
    {
        $payload = ['name' => 'A', 'phone' => '01811112222', 'topic' => 'complaint', 'message' => 'A long enough complaint message.'];

        $this->onPortal('public')->from('/grievance')->post('/grievance', [...$payload, 'website' => 'spam'])->assertSessionHasErrors('website');

        foreach (range(1, 4) as $attempt) {
            $this->onPortal('public')->from('/grievance')->post('/grievance', $payload)->assertRedirect('/grievance');
        }
        $this->onPortal('public')->from('/grievance')->post('/grievance', $payload)->assertStatus(429);
    }

    public function test_tracking_number_shows_only_the_status(): void
    {
        DB::table('enquiries')->insert([
            'name' => 'Private Person',
            'phone' => '01899998888',
            'topic' => 'complaint',
            'message' => 'A private complaint message.',
            'status' => 'contacted',
            'tracking_number' => 'GRV-261108-ABCDEF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->publicGet('/grievance?track=grv-261108-abcdef')
            ->assertOk()
            ->assertSee(__('public_trust.grievance_status_contacted'))
            ->assertDontSee('Private Person')
            ->assertDontSee('01899998888')
            ->assertDontSee('A private complaint message.');

        $this->publicGet('/grievance?track=GRV-000000-AAAAAA')->assertOk()->assertSee(__('public_trust.grievance_track_not_found'));
    }

    public function test_grievance_contact_block_appears_only_when_a_name_is_filled_in(): void
    {
        $this->publicGet('/grievance')->assertOk()->assertDontSee(__('public_trust.grievance_contact_heading'));

        $this->saveTrustText(['trust_grievance_contact_name' => 'Md Example', 'trust_grievance_contact_phone' => '01700000000'])->assertRedirect();

        $this->publicGet('/grievance')
            ->assertOk()
            ->assertSee(__('public_trust.grievance_contact_heading'))
            ->assertSee('Md Example')
            ->assertSee('01700000000')
            ->assertDontSee(__('public_trust.grievance_contact_email'));
    }

    public function test_status_change_message_names_the_product_problem_topic(): void
    {
        $id = DB::table('enquiries')->insertGetId([
            'name' => 'Reporter', 'phone' => '01700001111', 'topic' => 'product_problem', 'message' => 'A long enough product report.',
            'status' => 'new', 'locale' => 'bn', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'editor', 'status' => 'active']))->onPortal('manage')
            ->post('/commands/enquiries/'.$id, ['status' => 'closed'])
            ->assertRedirect();

        $this->assertDatabaseHas('enquiries', ['id' => $id, 'status' => 'closed']);
        $this->assertStringContainsString('পণ্যের সমস্যা বা পার্শ্বপ্রতিক্রিয়া', (string) (config('madina.sms.sent')[0]['message'] ?? ''));
    }

    public function test_articles_ship_empty_and_stay_hidden_until_one_is_published(): void
    {
        $this->assertSame(0, HealthArticle::query()->count());
        $this->publicGet('/articles')->assertNotFound();
        $this->publicGet('/')->assertOk()->assertDontSee('href="/articles"', false);

        $draft = HealthArticle::factory()->draft()->create();

        $this->publicGet('/articles')->assertNotFound();
        $this->publicGet('/articles/'.$draft->id)->assertNotFound();
    }

    public function test_owner_writes_and_publishes_an_article_with_a_photo(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/public-trust/articles', [
                'title' => 'শীতে হাত ধোয়ার অভ্যাস',
                'body' => "প্রথম অনুচ্ছেদ।\n\nদ্বিতীয় অনুচ্ছেদ।",
                'published' => '1',
                'image' => UploadedFile::fake()->image('wash.jpg', 400, 300),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $article = HealthArticle::query()->sole();
        $this->beforeApplicationDestroyed(fn () => @unlink(public_path(ltrim((string) $article->image_url, '/'))));
        $this->assertNotNull($article->published_at);
        $this->assertStringStartsWith('/branding/site-photos/article-', (string) $article->image_url);

        $this->publicGet('/articles')->assertOk()->assertSee('শীতে হাত ধোয়ার অভ্যাস')->assertSee((string) $article->image_url, false);
        $this->publicGet('/articles/'.$article->id)->assertOk()->assertSee('দ্বিতীয় অনুচ্ছেদ।')->assertSee(__('public_trust.articles_disclaimer'));
        $this->publicGet('/')->assertOk()->assertSee('href="/articles"', false);

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/public-trust/articles/'.$article->id, ['title' => $article->title, 'body' => $article->body, 'published' => '0'])
            ->assertRedirect();

        $this->assertNull($article->fresh()->published_at);
        $this->publicGet('/articles/'.$article->id)->assertNotFound();
    }

    public function test_article_html_is_escaped(): void
    {
        $article = HealthArticle::factory()->create(['title' => '<img src=x onerror=alert(1)>', 'body' => '<script>alert(2)</script>']);

        $this->publicGet('/articles/'.$article->id)
            ->assertOk()
            ->assertDontSee('<script>alert(2)</script>', false)
            ->assertDontSee('<img src=x', false);
    }

    public function test_owner_and_website_staff_open_the_editor_and_others_cannot(): void
    {
        HealthArticle::factory()->draft()->create(['title' => 'Draft article']);

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/public-trust')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'public-trust')
                ->where('data.public_trust.values.trust_tin', '')
                ->where('data.public_trust.drafts.bn.refund_title', 'ফেরত ও রিফান্ড নীতি')
                ->where('data.public_trust.articles.0.title', 'Draft article'));

        $this->actingAs(User::factory()->create(['role' => 'editor', 'status' => 'active']))->onPortal('manage')->get('/public-trust')->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'inventory', 'status' => 'active']))->onPortal('manage')->get('/public-trust')->assertForbidden();
    }

    public function test_people_without_website_permission_cannot_change_trust_content(): void
    {
        $inventory = User::factory()->create(['role' => 'inventory', 'status' => 'active']);

        $this->actingAs($inventory)->onPortal('manage')
            ->post('/commands/public-trust/text', ['values' => ['trust_tin' => '999']])
            ->assertForbidden();

        $this->assertSame('', PublicTrustContent::field('tin'));

        $dealer = $this->dealerAt(null);
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/public-trust/articles', ['title' => 'x', 'body' => 'y', 'published' => '1'])
            ->assertForbidden();
        $this->assertSame(0, HealthArticle::query()->count());
    }

    public function test_only_known_settings_can_be_saved_from_the_editor(): void
    {
        $this->saveTrustText(['trust_tin' => '111', 'footer_phone' => '+8800000000'])->assertRedirect();

        $this->assertSame('111', PublicTrustContent::field('tin'));
        $this->assertNotSame('+8800000000', SiteCustomization::all()['footer_phone']);
    }
}
