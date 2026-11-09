<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/** The ledger file's "Done by" column names the person who did the work. */
class LedgerEntryAuthorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_journal_entry_records_who_made_it_and_the_ledger_file_shows_them(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'name' => 'The Owner']);
        $this->actingAs($owner);

        DB::transaction(fn () => app(CommissionWallet::class)->journal('test.entry.1', 'Test entry', [
            ['account' => 'company_fund_expense', 'user_id' => null, 'debit' => 1000, 'credit' => 0],
            ['account' => 'manual_payout_clearing', 'user_id' => null, 'debit' => 0, 'credit' => 1000],
        ]));

        $this->assertDatabaseHas('journal_entries', ['event_key' => 'test.entry.1', 'actor_id' => $owner->id]);
        $sheet = $this->ledgerSheet();
        $this->assertStringContainsString('The Owner', $sheet);
        $this->assertStringContainsString((string) __('portal.rpt_col_done_by'), $sheet);
    }

    /** The rows sheet of the ledger file in the Downloads menu, which replaced the account book page. */
    private function ledgerSheet(): string
    {
        $day = now('Asia/Dhaka')->format('Y-m-d');
        $file = $this->onPortal('sadmin')->get('/commands/operations/reports?type=ledger&format=xlsx&from='.$day.'&to='.$day)
            ->assertOk()->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($file);

        return $sheet;
    }

    public function test_the_additional_rank_reward_has_a_name_in_both_languages(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $this->assertNotSame('portal.reward_program_additional_rank', __('portal.reward_program_additional_rank', [], $locale));
        }
    }
}
