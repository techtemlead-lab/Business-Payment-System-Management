<?php

namespace Tests\Feature;

use App\Domain\Payouts\WithdrawalWeek;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner, 2026-11-27: a withdrawal is asked for on any day and at any hour; it is paid only on the weekly
 * payout day (Thursday, Bangladesh day). 2026-11-23 is a Monday, 2026-11-26 and 2026-12-03 are Thursdays.
 */
class WithdrawalWeekTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        // What a real install starts with (the test settings switch the payout day off for older tests).
        config(['madina.withdrawal_weekly_window' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_request_on_monday_night_is_accepted_and_names_the_next_thursday(): void
    {
        $this->at('2026-11-23 21:00:00');
        $dealer = $this->partnerWithWallet('5000.00');

        $response = $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('1000.00'))->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('withdrawals')->where('user_id', $dealer->id)->count());
        $this->assertSame('4000.00', DB::table('wallets')->where('user_id', $dealer->id)->value('available'));
        $flash = (string) $response->getSession()->get('success');
        $this->assertStringContainsString(__('portal.flash_withdrawal_requested'), $flash);
        $this->assertStringContainsString(__('office_withdrawal.payout_line_office', ['day' => 'Thursday', 'date' => '26 November 2026']), $flash);
        $this->assertStringContainsString('Payment is made only on Thursday.', $flash);
        $json = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', $this->office('500.00'))->assertCreated();
        $this->assertSame('2026-11-26', $json->json('data.payout_on'));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'withdrawal.refused_weekday')->count());
    }

    public function test_every_day_of_the_week_takes_a_request_late_at_night(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');
        foreach (['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28', '2026-11-29'] as $day) {
            $this->at($day.' 23:30:00');
            $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('300.00'))->assertSessionHasNoErrors();
        }

        $this->assertSame(7, DB::table('withdrawals')->where('user_id', $dealer->id)->where('status', 'requested')->count());
        $this->assertSame('2900.00', DB::table('wallets')->where('user_id', $dealer->id)->value('available'));
    }

    public function test_a_request_on_the_payout_day_is_paid_the_next_week_by_the_bangladesh_clock(): void
    {
        $week = app(WithdrawalWeek::class);

        $this->assertSame('2026-11-26', $week->payoutDateFor(CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Dhaka'))->toDateString());
        // Wednesday 23:59:59 in Dhaka is still Wednesday (17:59:59 UTC): paid the next day.
        $this->assertSame('2026-11-26', $week->payoutDateFor(CarbonImmutable::parse('2026-11-25 23:59:59', 'Asia/Dhaka'))->toDateString());
        // 2026-11-25 18:00 UTC is already Thursday 00:00 in Dhaka: paid on the next week's Thursday.
        $this->assertSame('2026-12-03', $week->payoutDateFor(CarbonImmutable::parse('2026-11-25 18:00:00', 'UTC'))->toDateString());
        $this->assertSame('2026-12-03', $week->payoutDateFor(CarbonImmutable::parse('2026-11-26 09:00:00', 'Asia/Dhaka'))->toDateString());
        $this->assertSame('2026-12-03', $week->payoutDateFor(CarbonImmutable::parse('2026-11-29 12:00:00', 'Asia/Dhaka'))->toDateString());

        $dealer = $this->partnerWithWallet('5000.00');
        $this->at('2026-11-26 09:00:00');
        $this->actingAs($dealer)->postJson('/api/v1/withdrawals', $this->office('300.00'))->assertCreated()->assertJsonPath('data.payout_on', '2026-12-03');
    }

    public function test_the_payout_line_names_the_day_and_date_in_bangla(): void
    {
        $line = app(WithdrawalWeek::class)->payoutLine('Office', CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Dhaka'), 'bn');

        $this->assertStringContainsString('টাকা দেওয়া হয় শুধু বৃহস্পতিবার।', (string) $line);
        $this->assertStringContainsString('২৬', (string) $line);
        $this->assertStringContainsString('২০২৬', (string) $line);
        $this->assertStringContainsString('অফিস', (string) $line);
    }

    public function test_a_replay_returns_the_first_request(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');
        $request = $this->office('1000.00');
        $this->at('2026-11-25 22:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $request)->assertSessionHasNoErrors();

        $this->at('2026-11-26 08:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $request)->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('withdrawals')->where('user_id', $dealer->id)->count());
        $this->assertSame('4000.00', DB::table('wallets')->where('user_id', $dealer->id)->value('available'));
    }

    public function test_the_partner_sees_the_payout_day_on_any_day(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');

        $this->at('2026-11-23 21:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money')->assertOk()
            ->assertInertia(fn ($page) => $page->where('withdraw.week.enabled', true)->where('withdraw.week.payout_day', 'Thursday')
                ->where('withdraw.week.payout_date', '26 November 2026')->missing('withdraw.week.open')->where('summary.available', '5000.00'));

        $this->at('2026-11-26 12:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money')->assertOk()
            ->assertInertia(fn ($page) => $page->where('withdraw.week.payout_date', '3 December 2026'));
    }

    public function test_the_owner_changes_the_payout_day_or_switches_it_off(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $finance = User::factory()->staff('finance')->create();
        $dealer = $this->partnerWithWallet('5000.00');
        $saturday = ['enabled' => true, 'payout_day' => 6, 'version' => 0, 'reason' => 'Office pays on Saturday'];

        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/settings/withdrawal-week', $saturday)->assertForbidden();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-week', $saturday)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal.week_changed', 'actor_id' => $owner->id]);
        $this->assertSame(['enabled' => true, 'payout_day' => 6, 'version' => 1], app(WithdrawalWeek::class)->settings());

        $this->at('2026-11-23 12:00:00');
        $this->actingAs($dealer)->postJson('/api/v1/withdrawals', $this->office('300.00'))->assertCreated()->assertJsonPath('data.payout_on', '2026-11-28');

        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-week', [...$saturday, 'enabled' => false, 'version' => 1])->assertSessionHasNoErrors();
        $this->at('2026-11-24 12:00:00');
        $response = $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('300.00'))->assertSessionHasNoErrors();
        $this->assertSame(__('portal.flash_withdrawal_requested'), $response->getSession()->get('success'));
        $this->actingAs($dealer)->postJson('/api/v1/withdrawals', $this->office('300.00'))->assertCreated()->assertJsonPath('data.payout_on', null);
        $this->assertSame(3, DB::table('withdrawals')->where('user_id', $dealer->id)->count());
    }

    public function test_an_older_saved_setting_with_an_application_day_still_reads(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        DB::table('business_settings')->insert(['key' => WithdrawalWeek::SettingKey, 'value' => json_encode(['enabled' => true, 'request_day' => 3, 'payout_day' => 4]), 'version' => 2, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $dealer = $this->partnerWithWallet('5000.00');

        $this->assertSame(['enabled' => true, 'payout_day' => 4, 'version' => 2], app(WithdrawalWeek::class)->settings());
        $this->at('2026-11-23 12:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('300.00'))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('withdrawals')->where('user_id', $dealer->id)->count());
    }

    public function test_a_bad_weekday_is_refused(): void
    {
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-week', ['enabled' => true, 'payout_day' => 9, 'version' => 0, 'reason' => 'Wrong day typed'])
            ->assertSessionHasErrors('payout_day');
        $this->assertSame(4, app(WithdrawalWeek::class)->settings()['payout_day']);
    }

    private function at(string $dhakaTime): void
    {
        $moment = CarbonImmutable::parse($dhakaTime, 'Asia/Dhaka')->utc();
        CarbonImmutable::setTestNow($moment);
        Carbon::setTestNow($moment);
    }

    /** @return array<string, string> */
    private function office(string $amount): array
    {
        return ['amount' => $amount, 'channel' => 'Office', 'idempotency_key' => (string) Str::uuid()];
    }

    private function partnerWithWallet(string $available): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], [
            'available' => $available, 'held' => '0.00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $dealer;
    }
}
