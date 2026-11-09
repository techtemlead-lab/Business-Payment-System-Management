<?php

namespace Tests\Feature;

use App\Models\HealthArticle;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BilingualArticlesAndFaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    private function inLanguage(string $locale, string $path): TestResponse
    {
        auth()->forgetGuards();

        return $this->onPortal('public')->withSession(['locale' => $locale])->get($path);
    }

    /** @param  array<string, string>  $fields */
    private function writeArticle(array $fields): TestResponse
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        return $this->actingAs($owner)->onPortal('sadmin')->post('/commands/public-trust/articles', [...$fields, 'published' => '1']);
    }

    public function test_each_language_sees_its_own_article(): void
    {
        $this->writeArticle([
            'title' => 'English title',
            'body' => 'English article text.',
            'title_bn' => 'বাংলা শিরোনাম',
            'body_bn' => 'বাংলা লেখা।',
        ])->assertRedirect()->assertSessionHas('success');

        $article = HealthArticle::query()->sole();

        $this->inLanguage('bn', '/articles')->assertOk()->assertSee('বাংলা শিরোনাম')->assertSee('বাংলা লেখা।')->assertDontSee('English title');
        $this->inLanguage('bn', '/articles/'.$article->id)->assertOk()->assertSee('বাংলা লেখা।')->assertDontSee('English article text.');
        $this->inLanguage('en', '/articles')->assertOk()->assertSee('English title')->assertSee('English article text.')->assertDontSee('বাংলা শিরোনাম');
        $this->inLanguage('en', '/articles/'.$article->id)->assertOk()->assertSee('English article text.')->assertDontSee('বাংলা লেখা।');
    }

    public function test_an_article_written_in_one_language_shows_that_one_to_everyone(): void
    {
        $this->writeArticle(['title_bn' => 'শুধু বাংলা', 'body_bn' => 'শুধু বাংলা লেখা।'])->assertRedirect()->assertSessionHas('success');
        $article = HealthArticle::query()->sole();

        $this->inLanguage('bn', '/articles/'.$article->id)->assertOk()->assertSee('শুধু বাংলা লেখা।');
        $this->inLanguage('en', '/articles/'.$article->id)->assertOk()->assertSee('শুধু বাংলা')->assertSee('শুধু বাংলা লেখা।');

        $article->update(['title' => 'Only English', 'body' => 'Only English text.', 'title_bn' => null, 'body_bn' => null]);

        $this->inLanguage('bn', '/articles/'.$article->id)->assertOk()->assertSee('Only English')->assertSee('Only English text.');
    }

    public function test_an_article_from_before_the_change_keeps_showing_in_both_languages(): void
    {
        $article = HealthArticle::factory()->create(['title' => 'Written in the old box', 'body' => 'Older article text.']);

        $this->inLanguage('bn', '/articles')->assertOk()->assertSee('Written in the old box');
        $this->inLanguage('bn', '/articles/'.$article->id)->assertOk()->assertSee('Older article text.');
        $this->inLanguage('en', '/articles/'.$article->id)->assertOk()->assertSee('Older article text.');
    }

    public function test_a_title_needs_its_text_in_the_same_language(): void
    {
        $this->writeArticle(['title' => 'Title with no text'])->assertSessionHasErrors('body');
        $this->writeArticle(['body_bn' => 'শিরোনাম ছাড়া লেখা'])->assertSessionHasErrors('title_bn');
        $this->assertSame(0, HealthArticle::query()->count());
    }

    public function test_an_empty_article_is_refused_with_one_clear_reason(): void
    {
        $this->writeArticle([])->assertSessionHasErrors([
            'title_bn' => __('public_trust.admin_article_needs_one_language'),
        ]);
        $this->assertSame(0, HealthArticle::query()->count());
    }

    public function test_the_editor_receives_both_languages(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        HealthArticle::factory()->create(['title' => 'English', 'body' => 'English text', 'title_bn' => 'বাংলা', 'body_bn' => 'বাংলা লেখা']);

        $row = data_get($this->actingAs($owner)->onPortal('sadmin')->get('/public-trust')->assertOk()->original->getData(), 'page.props.data.public_trust.articles.0');

        $this->assertSame('বাংলা', $row['title_bn']);
        $this->assertSame('বাংলা লেখা', $row['body_bn']);
    }

    public function test_a_faq_row_shows_each_language_its_own_words(): void
    {
        SiteCustomization::save(['faq_items' => [
            ['question' => 'Added question?', 'answer' => 'Added answer.', 'question_bn' => 'নতুন প্রশ্ন?', 'answer_bn' => 'নতুন উত্তর।'],
        ]]);

        $this->inLanguage('bn', '/faq')->assertOk()->assertSee('নতুন প্রশ্ন?')->assertSee('নতুন উত্তর।')->assertDontSee('Added question?');
        $this->inLanguage('en', '/faq')->assertOk()->assertSee('Added question?')->assertSee('Added answer.')->assertDontSee('নতুন প্রশ্ন?');
    }

    public function test_a_faq_row_with_one_language_shows_that_one_to_everyone(): void
    {
        SiteCustomization::save(['faq_items' => [
            ['question' => '', 'answer' => '', 'question_bn' => 'শুধু বাংলা প্রশ্ন?', 'answer_bn' => 'শুধু বাংলা উত্তর।'],
            ['question' => 'Only English question?', 'answer' => 'Only English answer.', 'question_bn' => '', 'answer_bn' => ''],
        ]]);

        foreach (['bn', 'en'] as $locale) {
            $this->inLanguage($locale, '/faq')->assertOk()
                ->assertSee('শুধু বাংলা প্রশ্ন?')
                ->assertSee('শুধু বাংলা উত্তর।')
                ->assertSee('Only English question?')
                ->assertSee('Only English answer.');
        }
    }

    public function test_a_faq_row_from_before_the_change_keeps_showing(): void
    {
        $rows = SiteCustomization::defaults()['faq_items'];
        $rows[] = ['question' => 'Older added row?', 'answer' => 'Older added answer.'];
        DB::table('content_pages')->updateOrInsert(['slug' => 'frontend_customization'], [
            'title' => 'Frontend Customization',
            'body' => json_encode(['faq_items' => $rows], JSON_UNESCAPED_UNICODE),
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SiteCustomization::forgetCache();

        $this->inLanguage('bn', '/faq')->assertOk()->assertSee('Older added row?')->assertSee(__('site.faq_item1_question', [], 'bn'));
        $this->inLanguage('en', '/faq')->assertOk()->assertSee('Older added row?')->assertSee($rows[0]['question']);
    }

    public function test_a_bangla_box_on_a_built_in_row_replaces_only_that_box(): void
    {
        $rows = SiteCustomization::defaults()['faq_items'];
        $rows[0]['question_bn'] = 'মালিকের লেখা প্রশ্ন?';
        SiteCustomization::save(['faq_items' => $rows]);

        $this->inLanguage('bn', '/faq')->assertOk()
            ->assertSee('মালিকের লেখা প্রশ্ন?')
            ->assertSee(__('site.faq_item1_answer', [], 'bn'))
            ->assertDontSee(__('site.faq_item1_question', [], 'bn'));
    }
}
