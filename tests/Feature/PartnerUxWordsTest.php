<?php

namespace Tests\Feature;

use App\Support\TranslationBundle;
use Tests\TestCase;

/** The partner_ux words exist in both languages, none is blank, and the screens receive the group. */
class PartnerUxWordsTest extends TestCase
{
    public function test_every_word_is_there_in_both_languages_and_none_is_blank(): void
    {
        $bangla = require lang_path('bn/partner_ux.php');
        $english = require lang_path('en/partner_ux.php');

        $this->assertSame(array_keys($bangla), array_keys($english));
        $this->assertSame([], array_keys(array_filter($bangla, fn (string $word): bool => trim($word) === '')));
        $this->assertSame([], array_keys(array_filter($english, fn (string $word): bool => trim($word) === '')));
    }

    public function test_the_six_common_questions_and_the_big_order_words_exist(): void
    {
        $words = TranslationBundle::for('bn')['partner_ux'];

        foreach (range(1, 6) as $number) {
            $this->assertArrayHasKey("faq{$number}_q", $words);
            $this->assertArrayHasKey("faq{$number}_a", $words);
        }
        foreach (['big_order_title', 'big_order_body', 'big_order_yes', 'big_order_back', 'unit_crore', 'unit_lakh', 'unit_thousand', 'unit_taka', 'search_only_in', 'search_all', 'file_choose', 'file_none'] as $key) {
            $this->assertArrayHasKey($key, $words);
        }
    }

    public function test_a_known_unit_and_category_have_a_bangla_word(): void
    {
        $words = TranslationBundle::for('bn')['partner_ux'];

        $this->assertSame('বয়াম', $words['unit_jar']);
        $this->assertSame('ত্বকের যত্ন', $words['category_skin_care']);
    }
}
