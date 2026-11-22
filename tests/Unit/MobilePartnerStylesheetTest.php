<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The phone stylesheet for the dealer and sub-dealer pages must never reach a desktop or tablet:
 * the whole file is one phone media query, and every new text key exists in Bangla and English.
 */
class MobilePartnerStylesheetTest extends TestCase
{
    public function test_every_rule_sits_inside_the_phone_media_query(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/mobile-partner.css');
        $css = trim((string) preg_replace('#/\*.*?\*/#s', '', $css));

        $this->assertStringStartsWith('@media (max-width: 767px) {', $css);

        $depth = 0;
        $closedAt = null;
        foreach (str_split($css) as $position => $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $closedAt = $position;
                    break;
                }
            }
        }

        $this->assertNotNull($closedAt, 'The media query is never closed.');
        $this->assertSame(strlen($css) - 1, $closedAt, 'There is a rule after the phone media query.');
    }

    public function test_new_phone_keys_exist_in_both_languages(): void
    {
        $keys = [
            'purchase_page' => ['mobile_more_show', 'mobile_more_hide', 'mobile_total', 'mobile_send'],
            'income' => ['zero_show', 'zero_hide'],
            'cr_partner' => ['card_more', 'card_less'],
        ];

        foreach ($keys as $group => $names) {
            foreach (['bn', 'en'] as $locale) {
                $lines = require dirname(__DIR__, 2)."/lang/{$locale}/{$group}.php";
                foreach ($names as $name) {
                    $this->assertArrayHasKey($name, $lines, "{$locale}/{$group}.{$name}");
                }
            }
        }
    }
}
