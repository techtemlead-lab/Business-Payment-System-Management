<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanyProfitFundWordingTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function languages(): array
    {
        return ['english' => ['en'], 'bangla' => ['bn']];
    }

    #[DataProvider('languages')]
    public function test_the_account_creation_screen_names_the_company_profit_fund(string $locale): void
    {
        $this->assertSame(__('funds.fund_company_profit', [], $locale), __('portal.acct_unassigned_reserve', [], $locale));
        $this->assertSame(__('funds.fund_company_profit', [], $locale), __('portal.retained_fund_unassigned', [], $locale));
    }

    #[DataProvider('languages')]
    public function test_no_language_file_still_names_the_old_area_fund(string $locale): void
    {
        $oldNames = $locale === 'en'
            ? ['Unassigned area fund', 'company area fund', 'Fund for areas without a dealer', 'sales area fund']
            : ['ডিলারবিহীন এলাকার ফান্ড', 'কোম্পানির এলাকা ফান্ড', 'বিক্রয় এলাকার ফান্ড'];

        foreach (File::files(lang_path($locale)) as $file) {
            $text = $file->getContents();
            foreach ($oldNames as $oldName) {
                $this->assertStringNotContainsString($oldName, $text, $file->getFilename().' still says "'.$oldName.'"');
            }
        }
    }

    #[DataProvider('languages')]
    public function test_the_manual_says_the_share_is_company_profit_with_its_source(string $locale): void
    {
        $line = (string) __('manual.area_dealers_places_s5', [], $locale);

        $this->assertStringContainsString(mb_strtolower((string) __('funds.fund_company_profit', [], $locale)), mb_strtolower($line));
        $this->assertStringContainsString($locale === 'en' ? 'source is recorded' : 'কোথা থেকে এসেছে তা লেখা থাকে', $line);
    }

    #[DataProvider('languages')]
    public function test_older_rows_are_described_as_the_older_reserve_not_as_company_profit(string $locale): void
    {
        $reason = (string) __('portal.retained_reason_unassigned', ['level' => 'x', 'account' => 'y'], $locale);

        $this->assertStringContainsString($locale === 'en' ? 'older area reserve' : 'পুরনো এলাকা রিজার্ভ', $reason);
    }
}
