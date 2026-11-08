<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The portal's language switch has a long label ("English") and a short one ("EN") in each
 * button. Only one may show at a time, or the button reads "EnglishEN".
 */
class PortalLanguageSwitchLabelTest extends TestCase
{
    private function stylesheet(): string
    {
        return File::get(resource_path('css/dashboard.css'));
    }

    public function test_the_short_label_is_hidden_unless_a_small_screen_rule_shows_it(): void
    {
        $css = $this->stylesheet();
        $rule = '.language-switcher .language-short{display:none}';
        $position = strpos($css, $rule);

        $this->assertNotFalse($position, 'The short label needs a default hidden rule.');
        $before = substr($css, 0, $position);
        $this->assertSame(substr_count($before, '{'), substr_count($before, '}'), 'The rule must apply on every screen size, so it cannot sit inside a media query.');
    }

    public function test_phones_and_tablets_still_swap_to_the_short_label(): void
    {
        $css = $this->stylesheet();

        $this->assertStringContainsString('.app-shell .language-switcher .language-full{display:none}', $css);
        $this->assertStringContainsString('.app-shell .language-switcher .language-short{display:inline}', $css);
        $this->assertStringContainsString('.app-shell .workspace-header .language-switcher .language-short{display:inline}', $css);
    }

    public function test_the_switch_still_has_both_labels_and_a_name_for_screen_readers(): void
    {
        $component = File::get(resource_path('js/components/LanguageSwitcher.tsx'));

        $this->assertStringContainsString('className="language-full"', $component);
        $this->assertStringContainsString('className="language-short" aria-hidden="true"', $component);
        $this->assertStringContainsString('aria-label={code === \'bn\'', $component);
    }
}
