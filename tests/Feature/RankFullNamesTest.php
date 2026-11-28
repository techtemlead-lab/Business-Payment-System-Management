<?php

namespace Tests\Feature;

use App\Support\RankLabel;
use Tests\TestCase;

/**
 * The client asked (2026-11-28) for the first three ranks to be written in full, never as MO, AM or SM. ED keeps its
 * short name. Stored keys stay mo, am, sm, me. The language files may not hold the bare codes as words again.
 */
class RankFullNamesTest extends TestCase
{
    public function test_the_visible_rank_name_is_the_full_name_in_both_languages(): void
    {
        $expected = [
            'en' => ['mo' => 'Marketing Officer', 'am' => 'Area Manager', 'sm' => 'Sales Manager', 'me' => 'ED'],
            'bn' => ['mo' => 'মার্কেটিং অফিসার', 'am' => 'এরিয়া ম্যানেজার', 'sm' => 'সেলস ম্যানেজার', 'me' => 'ED'],
        ];
        foreach ($expected as $locale => $names) {
            foreach ($names as $key => $name) {
                $this->assertSame($name, RankLabel::name($key, $locale), $locale.' '.$key);
            }
        }
        $this->assertSame('MO', RankLabel::of('mo'), 'The stored-code label is unchanged.');
    }

    public function test_the_one_time_prize_names_carry_the_full_rank_name(): void
    {
        $this->assertSame('Marketing Officer Rank Reward (One time)', __('portal.settle_kind_rank_mo', [], 'en'));
        $this->assertSame('Area Manager Rank Reward (One time)', __('cr21_income.head_rank_am', [], 'en'));
        $this->assertSame('সেলস ম্যানেজার র‍্যাঙ্ক পুরস্কার (ওয়ান টাইম)', __('portal.settle_kind_rank_sm', [], 'bn'));
    }

    public function test_no_language_value_shows_mo_am_or_sm_as_a_word(): void
    {
        foreach (['en', 'bn'] as $locale) {
            foreach (glob(dirname(__DIR__, 2).'/lang/'.$locale.'/*.php') ?: [] as $file) {
                if (basename($file) === 'site.php') {
                    continue;
                }
                foreach (file($file) ?: [] as $number => $line) {
                    $this->assertSame(0, preg_match('/=>.*\b(MO|AM|SM)s?\b/u', $line), $locale.'/'.basename($file).':'.($number + 1).' shows a bare rank code.');
                }
            }
        }
    }
}
