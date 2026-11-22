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
 * Owner, 2026-11-21: a withdrawal is asked for only on Wednesday (Bangladesh day) and paid on Thursday.
 * 2026-11-25 is a Wednesday and 2026-11-26 a Thursday.
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
        // What a real install starts with (the test settings switch the weekly rule off for older tests).
        config(['madina.withdrawal_weekly_window' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_request_on_wednesday_goes_through_and_names_the_thursday(): void
    {
        $this->at('2026-11-25 10:30:00');
        $dealer = $this->partnerWithWallet('5000.00');

        $response = $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('1000.00'))->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('withdrawals')->where('user_id', $dealer->id)->count());
        $this->assertStringContainsString(__('office_withdrawal.payout_line_office', ['day' => 'Thursday', 'date' => '26 November 2026']), (string) $response->getSession()->get('success'));
        $json = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', $this->office('500.00'))->assertCreated();
        $this->assertSame('2026-11-26', $json->json('data.payout_on'));
    }

    public function test_every_other_weekday_is_refused_with_the_day_rule_and_moves_no_money(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');
        foreach (['2026-11-23', '2026-11-24', '2026-11-26', '2026-11-27', '2026-11-28', '2026-11-29'] as $day) {
            $this->at($day.' 12:00:00');
            $expected = app(WithdrawalWeek::class)->closedMessage();

            $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('1000.00'))
                ->assertSessionHasErrors(['amount' => $expected]);
        }

        $this->assertSame(0, DB::table('withdrawals')->where('user_id', $dealer->id)->count());
        $this->assertSame('5000.00', DB::table('wallets')->where('user_id', $dealer->id)->value('available'));
        $this->assertSame(6, DB::table('audit_events')->where('action', 'withdrawal.refused_weekday')->count());
    }

    public function test_the_day_follows_the_bangladesh_clock_at_midnight(): void
    {
        $week = app(WithdrawalWeek::class);

        // Tuesday 23:59:59 in Dhaka is still Tuesday, though it is 17:59:59 UTC.
        $this->assertFalse($week->isOpen(CarbonImmutable::parse('2026-11-24 23:59:59', 'Asia/Dhaka')));
        $this->assertTrue($week->isOpen(CarbonImmutable::parse('2026-11-25 00:00:00', 'Asia/Dhaka')));
        $this->assertTrue($week->isOpen(CarbonImmutable::parse('2026-11-25 23:59:59', 'Asia/Dhaka')));
        $this->assertFalse($week->isOpen(CarbonImmutable::parse('2026-11-26 00:00:00', 'Asia/Dhaka')));
        // 2026-11-24 18:30 UTC is already Wednesday 00:30 in Dhaka.
        $this->assertTrue($week->isOpen(CarbonImmutable::parse('2026-11-24 18:30:00', 'UTC')));

        $dealer = $this->partnerWithWallet('5000.00');
        $this->at('2026-11-25 00:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('300.00'))->assertSessionHasNoErrors();
        $this->at('2026-11-26 00:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('300.00'))->assertSessionHasErrors('amount');
        $this->assertSame(1, DB::table('withdrawals')->where('user_id', $dealer->id)->count());
    }

    public function test_the_refusal_names_the_days_and_the_next_wednesday_in_bangla(): void
    {
        $this->at('2026-11-26 09:00:00');

        $message = app(WithdrawalWeek::class)->closedMessage(null, 'bn');

        $this->assertStringContainsString('উইথড্রোর আবেদন শুধু বুধবার করা যায়। টাকা পাবেন বৃহস্পতিবার।', $message);
        $this->assertStringContainsString('২', $message);
        $this->assertStringContainsString('২০২৬', $message);
        $this->assertSame('2026-12-02', app(WithdrawalWeek::class)->nextRequestDate()->toDateString());
    }

    public function test_a_replay_on_thursday_returns_the_wednesday_request_without_a_refusal(): void
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

    public function test_the_partner_sees_the_day_rule_instead_of_the_request_button(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');

        $this->at('2026-11-23 12:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money')->assertOk()
            ->assertInertia(fn ($page) => $page->where('withdraw.week.open', false)->where('withdraw.week.message', app(WithdrawalWeek::class)->closedMessage())
                ->where('summary.available', '5000.00'));

        $this->at('2026-11-25 12:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money')->assertOk()
            ->assertInertia(fn ($page) => $page->where('withdraw.week.open', true)->where('withdraw.week.message', null)->where('withdraw.week.payout_date', '26 November 2026'));
    }

    public function test_the_owner_changes_the_days_or_switches_the_rule_off(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $finance = User::factory()->staff('finance')->create();
        $dealer = $this->partnerWithWallet('5000.00');
        $friday = ['enabled' => true, 'request_day' => 5, 'payout_day' => 6, 'version' => 0, 'reason' => 'Office is closed on Wednesday'];

        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/settings/withdrawal-week', $friday)->assertForbidden();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-week', $friday)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal.week_changed', 'actor_id' => $owner->id]);

        $this->at('2026-11-25 12:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('300.00'))->assertSessionHasErrors('amount');
        $this->at('2026-11-27 12:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('300.00'))->assertSessionHasNoErrors();
        $this->assertSame('2026-11-28', app(WithdrawalWeek::class)->payoutDateFor(CarbonImmutable::now())->toDateString());

        CarbonImmutable::setTestNow();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-week', [...$friday, 'enabled' => false, 'version' => 1])->assertSessionHasNoErrors();
        $this->at('2026-11-23 12:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->office('300.00'))->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('withdrawals')->where('user_id', $dealer->id)->count());
    }

    public function test_a_bad_weekday_is_refused(): void
    {
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-week', ['enabled' => true, 'request_day' => 9, 'payout_day' => 4, 'version' => 0, 'reason' => 'Wrong day typed'])
            ->assertSessionHasErrors('request_day');
        $this->assertSame(3, app(WithdrawalWeek::class)->settings()['request_day']);
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
