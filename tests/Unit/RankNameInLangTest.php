<?php

namespace Tests\Unit;

use App\Support\RankLabel;
use PHPUnit\Framework\TestCase;

/**
 * The top rank, stored as the key `me`, is called ED (owner, 2026-11-21). The key and every stored value stay `me`;
 * only the name people see changes. This scans every Bangla and English lang value so "ME" is never shown as a rank
 * name again, and checks the one label map used by PHP and by the React screens.
 */
class RankNameInLangTest extends TestCase
{
    public function test_the_top_rank_key_is_shown_as_ed_and_the_other_ranks_keep_their_letters(): void
    {
        $this->assertSame('ED', RankLabel::of('me'));
        $this->assertSame('ED', RankLabel::of(' ME '));
        $this->assertSame('MO', RankLabel::of('mo'));
        $this->assertSame('AM', RankLabel::of('am'));
        $this->assertSame('SM', RankLabel::of('sm'));
        $this->assertSame('DEALER', RankLabel::of('dealer'));
    }

    public function test_the_visible_name_is_the_full_name_for_the_first_three_ranks(): void
    {
        $this->assertSame('ED', RankLabel::name('me'));
        $this->assertSame('DEALER', RankLabel::name('dealer'));
    }

    public function test_the_react_label_map_is_the_same_as_the_php_one(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/shared/rankLabel.ts');

        $this->assertSame(1, preg_match('/const SHOWN: Record<string, string> = \{([^}]*)\};/', $source, $block), 'rankLabel.ts must hold one SHOWN map.');
        preg_match_all("/(\w+):\s*'([^']+)'/", $block[1], $pairs, PREG_SET_ORDER);
        $shown = [];
        foreach ($pairs as $pair) {
            $shown[$pair[1]] = $pair[2];
        }

        $this->assertSame(RankLabel::Shown, $shown);
    }

    public function test_the_rank_names_in_both_languages_say_ed(): void
    {
        $root = dirname(__DIR__, 2);
        $names = [
            'portal.net_rank_me', 'portal.rank_badge_me', 'portal.rank_stat_me', 'portal.net_badge_me',
            'portal.settle_kind_rank_me', 'cr21_income.head_rank_me', 'cr21_income.hint_rank_me',
            'commission_page.part_rank_royalty_me', 'commission_page.part_rank_incentive_me', 'partner_hub.jargon_me',
        ];

        foreach (['bn', 'en'] as $locale) {
            foreach ($names as $path) {
                [$group, $key] = explode('.', $path, 2);
                $messages = require $root.'/lang/'.$locale.'/'.$group.'.php';
                $this->assertStringContainsString('ED', (string) ($messages[$key] ?? ''), $locale.'/'.$path);
            }
            $portal = require $root.'/lang/'.$locale.'/portal.php';
            $this->assertSame('ED', $portal['net_rank_me'], $locale.'/portal.net_rank_me');
        }
    }

    public function test_no_lang_value_uses_me_as_a_rank_name(): void
    {
        $found = [];

        foreach (['bn', 'en'] as $locale) {
            foreach (glob(dirname(__DIR__, 2).'/lang/'.$locale.'/*.php') ?: [] as $file) {
                $group = basename($file, '.php');
                $messages = require $file;
                if (! is_array($messages)) {
                    continue;
                }
                foreach ($this->flatten($messages) as $key => $value) {
                    // Placeholder names such as :me_count are code, not words people read.
                    $clean = (string) preg_replace('/:[a-z_]+/i', '', $value);
                    if (preg_match('/(?<![A-Za-z])ME(?![A-Za-z])/u', $clean) === 1) {
                        $found[] = $locale.'/'.$group.'.'.$key;
                    }
                }
            }
        }

        $this->assertSame([], $found, "The top rank is ED, not ME. Use ED in:\n".implode("\n", $found));
    }

    /**
     * @param  array<string, mixed>  $messages
     * @return array<string, string>
     */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix.$key.'.');
            } elseif (is_string($value)) {
                $flat[$prefix.$key] = $value;
            }
        }

        return $flat;
    }
}
