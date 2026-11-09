<?php

namespace Tests\Feature;

use App\Domain\Finance\ReportData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The office Treasury pages open for the people who use them, in both languages; the Downloads
 * menu offers exactly the files a person may take; and the words on these pages stay plain.
 */
class TreasuryOfficePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array{string, string}> */
    public static function pages(): array
    {
        return [
            'funds' => ['/treasury/funds', 'TreasuryFunds'],
            'one fund' => ['/treasury/funds/marketing', 'TreasuryFund'],
            'money out of a fund' => ['/treasury/funds/marketing?view=out', 'TreasuryFund'],
            'order report' => ['/treasury/orders', 'TreasuryOrders'],
            'event log' => ['/treasury/events', 'TreasuryEvents'],
            'partners money' => ['/treasury/partners', 'TreasuryPartners'],
            'money tasks' => ['/treasury/work', 'TreasuryWork'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_owner_can_open_the_page_in_both_languages(string $path, string $component): void
    {
        $owner = User::factory()->staff()->create();

        foreach (['bn', 'en'] as $locale) {
            $this->actingAs($owner)->withSession(['locale' => $locale])->onPortal('sadmin')->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component)->where('section', 'treasury')->has('tabs.waiting'));
        }
    }

    public function test_someone_who_cannot_open_the_page_is_refused(): void
    {
        $this->actingAs(User::factory()->staff('support')->create())->onPortal('manage')->get('/treasury/funds')->assertForbidden();
    }

    public function test_the_downloads_menu_offers_every_file_the_owner_may_take(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury/orders')->assertInertia(function (Assert $page) use ($owner): void {
            $files = collect($page->toArray()['props']['downloads']['groups'])->flatMap(fn (array $group): array => $group['reports'])->sort()->values()->all();

            $this->assertEqualsCanonicalizing(ReportData::Types, $files);
            $this->assertEqualsCanonicalizing(app(ReportData::class)->availableTo($owner), $files);
            $page->where('downloads.statements', true);
        });
    }

    public function test_the_accountant_statements_are_only_offered_with_the_finance_right(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->onPortal('manage')->get('/treasury/orders')
            ->assertInertia(fn (Assert $page) => $page->where('downloads.statements', true));

        $reportsOnly = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['reports']]]);
        $this->actingAs($reportsOnly)->onPortal('manage')->get('/treasury/orders')->assertOk()
            ->assertInertia(function (Assert $page) use ($reportsOnly): void {
                $files = collect($page->toArray()['props']['downloads']['groups'])->flatMap(fn (array $group): array => $group['reports'])->values()->all();

                $this->assertEqualsCanonicalizing(app(ReportData::class)->availableTo($reportsOnly), $files);
                $page->where('downloads.statements', false);
            });
    }

    public function test_every_word_on_these_pages_is_plain(): void
    {
        foreach (['bn', 'en'] as $locale) {
            $words = implode(' ', Lang::get('treasury', [], $locale));

            $this->assertDoesNotMatchRegularExpression('/\b(debit|credit|ledger|journal|reversal|clawback|settlement|tds|allocation|reserve)\b/iu', $words, "A hard word in {$locale}");
        }
    }
}
