<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualDuplicateKeysTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function languages(): array
    {
        return ['english' => ['en'], 'bangla' => ['bn']];
    }

    #[DataProvider('languages')]
    public function test_the_withdrawal_steps_are_defined_once_in_the_manual(string $locale): void
    {
        $source = File::get(lang_path($locale.'/manual.php'));

        foreach (['payouts_settlements_s1', 'payouts_settlements_purpose'] as $key) {
            $this->assertSame(1, preg_match_all("/^\s*'".$key."'\s*=>/m", $source), $key.' must be defined once in '.$locale);
        }
    }

    #[DataProvider('languages')]
    public function test_the_withdrawal_step_names_the_menu_the_staff_really_see(string $locale): void
    {
        $step = (string) __('manual.payouts_settlements_s1', [], $locale);

        $this->assertStringContainsString((string) __('portal.nav_module_payouts', [], $locale), $step);
        $this->assertStringContainsString((string) __('portal.payouts_hub', [], $locale), $step);
    }
}
