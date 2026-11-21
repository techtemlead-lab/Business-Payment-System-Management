<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\CommissionNamesInLangTest;

/**
 * The client saw old commission and fund names in the Treasury filter lists and table headers, which are built
 * from keys at run time. This opens the Treasury pages in both languages and fails when any text the page sends
 * (filter options, fund and type names, table headers and rows) holds an old name.
 */
class CommissionNamesOnTreasuryPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return [
            'funds' => ['/treasury/funds'],
            'one fund' => ['/treasury/funds/marketing'],
            'sub-dealer extra fund' => ['/treasury/funds/sub_dealer_extra'],
            'money out of a fund' => ['/treasury/funds/marketing?view=out'],
            'order report' => ['/treasury/orders'],
            'event log' => ['/treasury/events'],
            'partners money' => ['/treasury/partners'],
            'money tasks' => ['/treasury/work'],
        ];
    }

    #[DataProvider('pages')]
    public function test_no_old_commission_name_is_sent_to_the_treasury_page(string $path): void
    {
        $owner = User::factory()->staff()->create();
        $found = [];

        foreach (['bn', 'en'] as $locale) {
            $this->actingAs($owner)->withSession(['locale' => $locale])->onPortal('sadmin')->get($path)->assertOk()
                ->assertInertia(function (Assert $page) use ($locale, &$found): void {
                    foreach ($this->texts($page->toArray()['props']) as $where => $text) {
                        foreach ($this->oldNamesIn($text) as $old) {
                            $found[] = $locale.' '.$where.' ['.$old.'] '.mb_substr($text, 0, 80);
                        }
                    }
                });
        }

        $this->assertSame([], $found, "Old commission or fund names on $path:\n".implode("\n", $found));
    }

    /** @return array<string, array{string}> */
    public static function partnerPages(): array
    {
        return ['my money' => ['/my-money'], 'my income' => ['/my-income']];
    }

    /** The partner money pages read the same names: income heads, tabs, filter options and the step columns. */
    #[DataProvider('partnerPages')]
    public function test_no_old_commission_name_is_sent_to_the_partner_money_pages(string $path): void
    {
        $dealer = User::factory()->dealer()->create();
        $found = [];

        foreach (['bn', 'en'] as $locale) {
            $this->actingAs($dealer)->withSession(['locale' => $locale])->onPortal('dealer')->get($path)->assertOk()
                ->assertInertia(function (Assert $page) use ($locale, &$found): void {
                    foreach ($this->texts($page->toArray()['props']) as $where => $text) {
                        foreach ($this->oldNamesIn($text) as $old) {
                            $found[] = $locale.' '.$where.' ['.$old.'] '.mb_substr($text, 0, 80);
                        }
                    }
                });
        }

        $this->assertSame([], $found, "Old commission names on $path:\n".implode("\n", $found));
    }

    /** @return list<string> The old names and old team wording found in a text. */
    private function oldNamesIn(string $text): array
    {
        $readable = $this->readable($text);
        $found = [];
        foreach (CommissionNamesInLangTest::OldNames as $old) {
            if (mb_stripos($readable, $old) !== false) {
                $found[] = $old;
            }
        }
        foreach (CommissionNamesInLangTest::OldTeamWordPatterns as $pattern) {
            if (preg_match($pattern, $readable) === 1) {
                $found[] = $pattern;
            }
        }

        return $found;
    }

    /** Text with the names that are allowed to contain an old word taken out. */
    private function readable(string $text): string
    {
        return str_ireplace(['সাব-ডিলার স্যালারি', 'sub-dealer salary'], '', (string) preg_replace('/:[a-z_]+/i', '', $text));
    }

    /**
     * Every string a page sends, except the full language bundle, the user manual and the search keywords. Those hold
     * the allowed referral-relationship words and words people may still type; the lang scan checks them one by one.
     *
     * @param  array<array-key, mixed>  $props
     * @return array<string, string>
     */
    private function texts(array $props, string $path = ''): array
    {
        $out = [];
        foreach ($props as $key => $value) {
            if (in_array($key, ['translations', 'lang', 'messages', 'i18n', 'userManualChapters', 'keywords'], true)) {
                continue;
            }
            if (is_array($value)) {
                $out += $this->texts($value, $path.$key.'.');
            } elseif (is_string($value) && $value !== '') {
                $out[$path.$key] = $value;
            }
        }

        return $out;
    }
}
