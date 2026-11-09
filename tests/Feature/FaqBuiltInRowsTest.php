<?php

namespace Tests\Feature;

use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FaqBuiltInRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    public function test_adding_a_row_keeps_the_built_in_rows_in_bangla(): void
    {
        $defaults = SiteCustomization::defaults()['faq_items'];
        $this->saveFaqRows([...$defaults, ['question' => 'QA extra question?', 'answer' => 'QA extra answer.']]);

        $this->withSession(['locale' => 'bn'])->get('/faq')->assertOk()
            ->assertSee(__('site.faq_item1_question', [], 'bn'))
            ->assertSee(__('site.faq_item5_answer', [], 'bn'))
            ->assertSee('QA extra question?')
            ->assertDontSee($defaults[0]['question']);
    }

    public function test_removing_a_row_keeps_the_rest_in_bangla(): void
    {
        $defaults = SiteCustomization::defaults()['faq_items'];
        unset($defaults[1]);
        $this->saveFaqRows(array_values($defaults));

        $this->withSession(['locale' => 'bn'])->get('/faq')->assertOk()
            ->assertSee(__('site.faq_item1_question', [], 'bn'))
            ->assertSee(__('site.faq_item3_question', [], 'bn'))
            ->assertDontSee(__('site.faq_item2_question', [], 'bn'));
    }

    public function test_a_row_the_owner_changed_stays_as_typed_in_both_languages(): void
    {
        $rows = SiteCustomization::defaults()['faq_items'];
        $rows[0]['answer'] = 'The owner wrote this answer.';
        $this->saveFaqRows($rows);

        $this->withSession(['locale' => 'bn'])->get('/faq')->assertOk()
            ->assertSee('The owner wrote this answer.')
            ->assertSee(__('site.faq_item2_question', [], 'bn'));
        $this->withSession(['locale' => 'en'])->get('/faq')->assertOk()
            ->assertSee('The owner wrote this answer.')
            ->assertSee(__('site.faq_item2_question', [], 'en'));
    }

    /** @param list<array{question: string, answer: string}> $rows */
    private function saveFaqRows(array $rows): void
    {
        DB::table('content_pages')->updateOrInsert(['slug' => 'frontend_customization'], [
            'title' => 'Frontend Customization',
            'body' => json_encode(['faq_items' => $rows], JSON_UNESCAPED_UNICODE),
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SiteCustomization::forgetCache();
    }
}
