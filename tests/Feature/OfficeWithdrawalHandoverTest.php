<?php

namespace Tests\Feature;

use App\Domain\Payouts\ManualPayoutService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owner, 2026-11-21 and 2026-11-27: an office withdrawal asked for on any day is handed over in cash at the
 * office on the next Thursday (the same Thursday when asked on Thursday before 14:00). Staff see it on the payout
 * list and mark it handed over; the usual payout path posts it once. Staff are not stopped from paying on another day.
 */
class OfficeWithdrawalHandoverTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        config(['madina.withdrawal_weekly_window' => true, 'madina.withdrawal_methods_default' => ['Office'], 'madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->approver = User::factory()->staff('finance')->create(['name' => 'Approver Rahim']);
        $this->payer = User::factory()->staff('finance')->create(['name' => 'Cashier Karim']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_thursday_office_handover_posts_once_with_a_cash_receipt(): void
    {
        [$dealer, $id] = $this->approvedOfficeWithdrawal('1500.00');

        $this->at('2026-11-26 11:00:00');
        $this->actingAs($this->payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/hand-over', ['paid_at' => '2026-11-26T10:55', 'note' => 'ID card checked'])
            ->assertSessionHasNoErrors();
        // A second tap (or a replayed request) changes nothing and reports the same receipt.
        $this->actingAs($this->payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/hand-over', ['paid_at' => '2026-11-26T10:56'])
            ->assertSessionHasNoErrors();

        $withdrawal = DB::table('withdrawals')->where('id', $id)->sole();
        $this->assertSame('paid', $withdrawal->status);
        $this->assertSame($this->payer->id, (int) $withdrawal->paid_by);
        $this->assertSame('2026-11-26 04:55:00', $withdrawal->paid_at);
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'manual-payout:'.$id)->count());
        $receipt = DB::table('payout_receipts')->where('withdrawal_id', $id)->sole();
        $this->assertSame('cash', $receipt->method);
        $this->assertSame($dealer->name, $receipt->received_by);
        $this->assertSame('0.00', DB::table('wallets')->where('user_id', $dealer->id)->value('held'));
        $this->assertSame(1, DB::table('withdrawal_events')->where('withdrawal_id', $id)->where('event', 'paid')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'withdrawal.paid')->where('subject', $id)->count());
        $this->assertCount(1, array_filter((array) config('madina.sms.sent', []), fn (array $sms): bool => ($sms['event'] ?? null) === 'withdrawal.paid'));
    }

    public function test_the_payout_list_shows_what_to_hand_over_and_what_to_check(): void
    {
        [$dealer, $id] = $this->approvedOfficeWithdrawal('1500.00');
        DB::table('users')->where('id', $dealer->id)->update(['nid' => encrypt('19901234567891234', false)]);

        $this->at('2026-11-26 09:00:00');
        $this->actingAs($this->payer)->onPortal('manage')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.office_payouts.0.id', $id)
                ->where('data.office_payouts.0.user_name', $dealer->name)
                ->where('data.office_payouts.0.dealer_code', $dealer->dealer_code)
                ->where('data.office_payouts.0.net_amount', '1500.00')
                ->where('data.office_payouts.0.status', 'approved')
                ->where('data.office_payouts.0.payout_date', '2026-11-26')
                ->where('data.office_payouts.0.due', true)
                ->where('data.office_payouts.0.id_hint', __('office_withdrawal.id_hint_nid', ['last' => '1234']))
                ->where('data.office_cash_total', '1500.00'));
    }

    public function test_a_monday_or_thursday_morning_request_is_due_that_thursday_and_a_thursday_afternoon_request_waits_a_week_without_blocking_staff(): void
    {
        [, $monday] = $this->approvedOfficeWithdrawal('1000.00', '2026-11-23 21:00:00');
        [, $morning] = $this->approvedOfficeWithdrawal('800.00', '2026-11-26 09:00:00');
        [, $thursday] = $this->approvedOfficeWithdrawal('700.00', '2026-11-26 15:00:00');

        $this->at('2026-11-26 16:00:00');
        $this->actingAs($this->payer)->onPortal('manage')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.office_payouts.0.id', $monday)
                ->where('data.office_payouts.0.payout_date', '2026-11-26')
                ->where('data.office_payouts.0.due', true)
                ->where('data.office_payouts.1.id', $morning)
                ->where('data.office_payouts.1.payout_date', '2026-11-26')
                ->where('data.office_payouts.1.due', true)
                ->where('data.office_payouts.2.id', $thursday)
                ->where('data.office_payouts.2.payout_date', '2026-12-03')
                ->where('data.office_payouts.2.due', false));

        // No staff-side day rule exists: the cashier may still hand over the Thursday afternoon request early.
        $this->actingAs($this->payer)->onPortal('manage')->post('/commands/withdrawals/'.$thursday.'/hand-over', ['paid_at' => '2026-11-26T15:55', 'note' => 'ID card checked'])
            ->assertSessionHasNoErrors();
        $this->assertSame('paid', DB::table('withdrawals')->where('id', $thursday)->value('status'));
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'manual-payout:'.$thursday)->count());
    }

    public function test_an_office_withdrawal_cannot_be_recorded_as_a_transfer_and_others_cannot_use_the_handover(): void
    {
        [, $id] = $this->approvedOfficeWithdrawal('1500.00');
        $this->at('2026-11-26 11:00:00');

        $this->actingAs($this->payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'bkash', 'wallet_number' => '01700000000', 'payment_reference' => 'BKX7Q2M9TA', 'paid_at' => '2026-11-26 10:00:00', 'note' => 'Sent',
        ])->assertSessionHasErrors(['method' => __('office_withdrawal.err_office_is_cash')]);

        $bkash = $this->approvedWithdrawal('bKash', '01700000000');
        $this->actingAs($this->payer)->onPortal('manage')->post('/commands/withdrawals/'.$bkash.'/hand-over', ['paid_at' => '2026-11-26T10:00'])
            ->assertSessionHasErrors(['withdrawal' => __('office_withdrawal.err_not_office')]);

        $partner = User::query()->where('role', 'dealer')->firstOrFail();
        $this->assertContains($this->actingAs($partner)->onPortal('dealer')->post('/commands/withdrawals/'.$id.'/hand-over', ['paid_at' => '2026-11-26T10:00'])->getStatusCode(), [403, 404]);
        $this->assertSame(0, DB::table('withdrawals')->where('status', 'paid')->count());
    }

    public function test_the_approver_does_not_hand_over_alone(): void
    {
        [, $id] = $this->approvedOfficeWithdrawal('1500.00');
        $this->at('2026-11-26 11:00:00');

        $this->actingAs($this->approver)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/hand-over', ['paid_at' => '2026-11-26T10:00'])
            ->assertSessionHasErrors('withdrawal');
        $this->assertSame('approved', DB::table('withdrawals')->where('id', $id)->value('status'));
    }

    /** @return array{0: User, 1: string} */
    private function approvedOfficeWithdrawal(string $amount, string $requestedAt = '2026-11-25 15:00:00'): array
    {
        $dealer = $this->partnerWithWallet('5000.00');
        $this->at($requestedAt);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => $amount, 'channel' => 'Office', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $id = (string) DB::table('withdrawals')->where('user_id', $dealer->id)->value('id');
        app(ManualPayoutService::class)->review($this->approver, $id, 'approved', 'ID and balance checked');

        return [$dealer, $id];
    }

    private function approvedWithdrawal(string $channel, string $destination): string
    {
        config(['madina.withdrawal_methods_default' => ['Office', $channel]]);
        $dealer = $this->partnerWithWallet('5000.00');
        $this->at('2026-11-25 16:00:00');
        $id = app(ManualPayoutService::class)->request($dealer, ['amount' => '1000.00', 'channel' => $channel, 'destination' => $destination, 'idempotency_key' => (string) Str::uuid()]);
        app(ManualPayoutService::class)->review($this->approver, $id, 'approved', 'Checked');
        $this->at('2026-11-26 11:00:00');

        return $id;
    }

    private function at(string $dhakaTime): void
    {
        $moment = CarbonImmutable::parse($dhakaTime, 'Asia/Dhaka')->utc();
        CarbonImmutable::setTestNow($moment);
        Carbon::setTestNow($moment);
    }

    private function partnerWithWallet(string $available): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '0172'.random_int(1000000, 9999999)]);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], [
            'available' => $available, 'held' => '0.00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $dealer;
    }
}
