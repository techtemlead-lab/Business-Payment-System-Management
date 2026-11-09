<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerWalletStatement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Money lines keep the sentence in the language it was written in, but the statement
 * is read in the reader's own language.
 */
class PartnerStatementWordsFollowReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_lines_saved_in_bangla_read_in_english_for_an_english_reader(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'en']);
        $this->journal($dealer, 'commissions.matured.7', __('portal.jr_commission_matured', [], 'bn'));
        $this->journal($dealer, 'commissions.order.'.Str::uuid(), __('portal.jr_commissions_earned', ['number' => 'MN-261109-AAA'], 'bn'));
        $this->journal($dealer, 'commissions.settlement.salary.2026-10.'.Str::uuid(), __('portal.jr_commission_settlement', ['kind' => __('portal.settle_kind_salary', [], 'bn'), 'month' => '2026-10'], 'bn'));
        $this->journal($dealer, 'commissions.settlement.12.'.$dealer->id.'.royalty', __('portal.jr_commission_settlement', ['kind' => __('portal.settle_kind_royalty', [], 'bn'), 'month' => '2026-09'], 'bn'));
        $this->journal($dealer, 'manual-payout:'.Str::uuid(), __('portal.wd_journal_manual_payout', [], 'bn'));

        $this->assertEqualsCanonicalizing([
            __('portal.jr_commission_matured', [], 'en'),
            'Earned commissions for MN-261109-AAA',
            'Commission Salary month closing 2026-10',
            'Commission Rank bonus month closing 2026-09',
            __('portal.wd_journal_manual_payout', [], 'en'),
        ], $this->wordsSeenBy($dealer));
    }

    public function test_lines_saved_in_english_read_in_bangla_for_a_bangla_reader(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'bn']);
        $this->journal($dealer, 'commissions.matured.8', __('portal.jr_commission_matured', [], 'en'));

        $this->assertSame([__('portal.jr_commission_matured', [], 'bn')], $this->wordsSeenBy($dealer));
    }

    public function test_a_typed_reason_stays_as_it_was_typed(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'en']);
        $this->journal($dealer, 'commissions.reversal.'.Str::uuid(), __('portal.jr_commission_reversal', ['reason' => 'পণ্য ফেরত'], 'bn'));

        $this->assertSame(['Commission correction: পণ্য ফেরত'], $this->wordsSeenBy($dealer));
    }

    public function test_a_reward_line_names_the_program_in_the_readers_language(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'en']);
        $id = DB::table('reserve_disbursements')->insertGetId([
            'program' => 'bike', 'user_id' => $dealer->id, 'amount' => '100.00', 'request_key' => (string) Str::uuid(),
            'reason' => 'Reward', 'status' => 'approved', 'created_by' => $dealer->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->journal($dealer, 'reserve.award.'.$id, __('portal.jr_reserve_award', ['program' => __('portal.reward_program_bike', [], 'bn')], 'bn'));

        $this->assertSame(['Approved bike reward'], $this->wordsSeenBy($dealer));
    }

    public function test_a_line_with_no_known_sentence_keeps_its_stored_words(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'en']);
        $this->journal($dealer, 'older.import.1', 'পুরনো হিসাবের লাইন');
        $this->journal($dealer, 'commissions.matured.9', 'আগের লেখা বাক্য');

        $this->assertEqualsCanonicalizing(['পুরনো হিসাবের লাইন', 'আগের লেখা বাক্য'], $this->wordsSeenBy($dealer));
    }

    /** @return list<string> */
    private function wordsSeenBy(User $dealer): array
    {
        app()->setLocale($dealer->preferredLocale());
        $today = now('Asia/Dhaka')->toDateString();

        return array_column(app(PartnerWalletStatement::class)->ledger($dealer, $today, $today)['lines']->items(), 'description');
    }

    private function journal(User $dealer, string $eventKey, string $description): void
    {
        $entry = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => $eventKey, 'description' => $description, 'created_at' => now()]);
        DB::table('journal_lines')->insert(['entry_id' => $entry, 'user_id' => $dealer->id, 'account' => 'wallet_liability', 'debit' => '0.00', 'credit' => '100.00']);
    }
}
