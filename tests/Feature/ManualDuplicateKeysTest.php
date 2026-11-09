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

        $this->assertStringContainsString((string) __('portal.payouts_hub', [], $locale), $step);
    }

    #[DataProvider('languages')]
    public function test_no_line_is_defined_twice_in_the_manual(string $locale): void
    {
        preg_match_all("/^\s*'([^']+)'\s*=>/m", File::get(lang_path($locale.'/manual.php')), $keys);

        $this->assertSame([], array_keys(array_filter(array_count_values($keys[1]), fn (int $count): bool => $count > 1)));
    }

    #[DataProvider('languages')]
    public function test_the_benefits_fund_step_names_the_menu_the_owner_really_sees(string $locale): void
    {
        $step = (string) __('manual.owner_benefits_fund_s1', [], $locale);

        $this->assertStringContainsString((string) __('portal.treasury', [], $locale), $step);
        $this->assertStringNotContainsString('sadmin', $step);
    }

    #[DataProvider('languages')]
    public function test_the_benefits_fund_outcome_names_the_page_the_owner_really_sees(string $locale): void
    {
        $this->assertStringContainsString((string) __('portal.treasury', [], $locale), (string) __('manual.owner_benefits_fund_purpose', [], $locale));
    }

    public function test_the_benefits_fund_purpose_keeps_the_wording_for_a_one_owner_company(): void
    {
        $this->assertStringStartsWith('Owner accounts need', (string) __('manual.owner_benefits_fund_purpose', [], 'en'));
        $this->assertStringContainsString('মালিক পক্ষের', (string) __('manual.owner_benefits_fund_purpose', [], 'bn'));
    }
}
