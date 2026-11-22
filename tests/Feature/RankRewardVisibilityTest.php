<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerWalletStatement;
use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\CommissionRuleParameter;
use App\Models\DealerRank;
use App\Models\Product;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A rank-related prize shows in the partner's own account: as its own income line once paid, and as a waiting
 * line with a plain reason while it is earned but not yet in the wallet. Nothing here changes an amount or a rule.
 */
class RankRewardVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function dealer(): User
    {
        return User::factory()->dealer()->create(['status' => 'active']);
    }

    private function purchase(User $buyer, string $cash, string $mrp): void
    {
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash, 'sub_dealer_price' => $buyer->role === 'sub_dealer' ? $cash : null]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);
    }

    /** @return array<string, mixed> */
    private function myMoney(User $partner): array
    {
        return $this->actingAs($partner)->onPortal('dealer')->get('/my-money')->assertOk()->assertInertia(fn (Assert $page) => $page->component('MyMoney'))
            ->viewData('page')['props'];
    }

    /** @return list<array<string, mixed>> */
    private function rewards(User $partner): array
    {
        return $this->myMoney($partner)['pending']['rewards'];
    }

    private function entry(string $key, User $partner, string $amount): void
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => $key, 'description' => 'Entry', 'created_at' => now()]);
        DB::table('journal_lines')->insert(['entry_id' => $id, 'user_id' => $partner->id, 'account' => 'wallet_liability', 'debit' => '0.00', 'credit' => $amount]);
    }

    private function award(User $partner, string $program, string $status, string $amount): int
    {
        $maker = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        return (int) DB::table('reserve_disbursements')->insertGetId([
            'program' => $program, 'user_id' => $partner->id, 'amount' => $amount, 'status' => $status, 'request_key' => (string) Str::uuid(), 'reason' => 'Award',
            'award_key' => in_array($program, ['bike', 'car'], true) ? $program.':'.$partner->id : null, 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function statementRows(User $partner): array
    {
        return $this->myMoney($partner)['table']['rows']['data'];
    }

    public function test_a_rank_prize_not_yet_paid_waits_with_a_plain_reason_and_then_shows_as_paid_with_a_notice(): void
    {
        $leader = $this->dealer();
        $other = $this->dealer();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()->subDays(60)]);

        // The rank is held but this month's target (৳30,000 for a dealer with no seat) is not met: the prize waits for that, and the line says so.
        $lines = $this->rewards($leader);
        $this->assertCount(1, $lines);
        $this->assertSame(['rank_mo', '5000.00', 'rank_prize_keep'], [$lines[0]['head'], $lines[0]['amount'], $lines[0]['reason']]);
        $this->assertSame(__('cr21_income.head_rank_mo'), $lines[0]['what']);
        $this->assertSame([], $this->rewards($other), 'Nobody else sees it.');
        $this->assertSame([], $this->statementRows($leader), 'It is not income yet.');

        // The month's target is met but the reserve is empty: now it waits for the office.
        $this->purchase($leader, '30000.00', '60000.00');
        $this->assertSame('rank_prize_office', $this->rewards($leader)[0]['reason']);
        // Neither the waiting prize (৳5,000) nor the dealer's month-end bonus (no amount before the month closes, owner's
        // rule of 2026-11-22) is in the pending total.
        $this->assertSame('0.00', $this->myMoney($leader)['pending']['total'], 'A waiting prize is not part of the pending total.');

        // The reserve is funded and the prize is paid by the engine, exactly as before.
        $this->purchase(User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']), '250000.00', '400000.00');
        $this->assertTrue(app(CommissionService::class)->claimOneTimeRankIncentive($leader->id)['success']);

        $this->assertSame([], $this->rewards($leader), 'No prize line is left once the prize is paid.');
        $this->assertContains('royalty', array_column($this->myMoney($leader)['pending']['month_end'], 'head'), 'The royalty note stays with the month-end items.');
        // The engine writes one wallet line for each reserve fund the prize was drawn from; they carry the same name and add up to the prize.
        $rows = collect($this->statementRows($leader))->where('type_label', __('cr21_income.head_rank_mo'));
        $this->assertNotEmpty($rows, 'The paid prize is its own line in the statement.');
        $this->assertSame(5000.0, round($rows->sum(fn (array $row): float => (float) $row['amount']), 2));
        $this->assertSame([], collect($this->statementRows($other))->where('type_label', __('cr21_income.head_rank_mo'))->all());

        $locale = $leader->preferredLocale();
        $notices = DB::table('account_notices')->where('user_id', $leader->id)->where('title', __('portal.notice_rank_prize_title', [], $locale))->get();
        $this->assertCount(1, $notices, 'The partner is told once when a one-time prize is paid, however many funds paid it.');
        $this->assertStringContainsString(__('portal.settle_kind_rank_mo', [], $locale), $notices[0]->body);
        $this->assertStringContainsString('5,000', $notices[0]->body);
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $leader->id)->where('title', __('portal.notice_settle_title', [], $locale))->count(), 'A one-time prize is not called a monthly commission.');
    }

    public function test_each_reached_rank_whose_prize_is_unpaid_has_its_own_line_and_a_paid_one_has_none(): void
    {
        $leader = $this->dealer();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'am', 'achieved_at' => now(), 'qualification_history' => [
            ['rank' => 'mo', 'achieved_at' => now()->subDay()->toIso8601String()], ['rank' => 'am', 'achieved_at' => now()->toIso8601String()],
        ]]);
        DB::table('rank_incentive_awards')->insert(['user_id' => $leader->id, 'rank' => 'mo', 'amount' => '5000.00', 'sources' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $lines = $this->rewards($leader);
        $this->assertSame(['rank_am'], array_column($lines, 'head'), 'The paid MO prize has no line; with the month\'s target not met there is no royalty note either.');

        $this->purchase($leader, '30000.00', '60000.00');
        $lines = $this->rewards($leader);

        $this->assertSame(['rank_am'], array_column($lines, 'head'), 'The paid MO prize has no line; the royalty note is not a prize.');
        $this->assertSame('50000.00', $lines[0]['amount']);
        $this->assertSame(['royalty', 'salary'], array_column($this->myMoney($leader)['pending']['month_end'], 'head'), 'Royalty and the bonus are month-end items, with no amount.');
        $this->assertSame([null, null], array_column($this->myMoney($leader)['pending']['month_end'], 'amount'));
    }

    public function test_a_bike_or_car_prize_waits_until_the_office_pays_it_and_the_reasons_follow_the_request(): void
    {
        $leader = $this->dealer();
        $other = $this->dealer();
        RewardMilestoneProgress::create(['user_id' => $leader->id, 'milestone_type' => 'bike', 'target_amount' => '5000000.00', 'leg_cap_amount' => '800000.00', 'achieved' => true, 'achieved_at' => now()]);

        $lines = $this->rewards($leader);
        $this->assertSame([['bike', '300000.00', 'prize_office']], array_map(fn (array $line): array => [$line['head'], $line['amount'], $line['reason']], $lines));
        $this->assertSame(__('cr21_income.head_bike'), $lines[0]['what']);
        $this->assertSame([], $this->rewards($other));

        $id = $this->award($leader, 'bike', 'requested', '300000.00');
        $this->assertSame('prize_review', $this->rewards($leader)[0]['reason']);

        // Paid: no waiting line; it is a statement row of the bike head.
        DB::table('reserve_disbursements')->where('id', $id)->update(['status' => 'credited']);
        $this->entry('reserve.award.'.$id, $leader, '300000.00');
        $this->assertSame([], $this->rewards($leader));
        $row = collect($this->statementRows($leader))->firstWhere('type_label', __('cr21_income.head_bike'));
        $this->assertSame('300000.00', $row['amount']);
        $this->assertSame([], collect($this->statementRows($other))->all());
    }

    public function test_a_car_prize_shows_with_the_rules_amount_and_is_left_out_when_the_rule_has_no_amount(): void
    {
        $leader = $this->dealer();
        RewardMilestoneProgress::create(['user_id' => $leader->id, 'milestone_type' => 'car', 'target_amount' => '500000000.00', 'leg_cap_amount' => '80000000.00', 'achieved' => true, 'achieved_at' => now()]);
        $this->assertSame([['car', '2500000.00']], array_map(fn (array $line): array => [$line['head'], $line['amount']], $this->rewards($leader)), 'The car prize is ৳25,00,000 by default.');

        $rule = CommissionRuleParameter::getRule('car_bike_incentive');
        $rule->update(['parameters' => [...$rule->parameters, 'car_award_amount' => '0.00']]);
        $this->assertSame([], $this->rewards($leader), 'With no amount in the rule there is nothing honest to show.');

        $rule->update(['parameters' => [...$rule->parameters, 'car_award_amount' => '500000.00']]);

        $this->assertSame([['car', '500000.00']], array_map(fn (array $line): array => [$line['head'], $line['amount']], $this->rewards($leader)));
    }

    public function test_the_royalty_and_the_sub_dealer_extra_rank_award_show_as_their_own_lines_only_to_their_owner(): void
    {
        $leader = $this->dealer();
        $other = $this->dealer();
        $this->entry('commissions.settlement.royalty.2026-09.x', $leader, '45.00');
        $this->entry('reserve.award.'.$this->award($leader, 'additional_rank', 'credited', '20.00'), $leader, '20.00');
        $this->entry('reserve.award.'.$this->award($leader, 'car', 'credited', '700.00'), $leader, '700.00');

        $rows = collect($this->statementRows($leader))->pluck('amount', 'type_label');
        $this->assertSame('45.00', $rows[__('cr21_income.head_royalty')]);
        $this->assertSame('20.00', $rows[__('cr21_income.head_incentive')]);
        $this->assertSame('700.00', $rows[__('cr21_income.head_car')]);
        $this->assertSame([], $this->statementRows($other));
    }

    public function test_the_dashboard_and_the_office_partner_page_show_the_same_waiting_prizes(): void
    {
        $leader = $this->dealer();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);

        $home = app(PartnerDashboardHome::class)->for($leader);
        // No purchase this month, so the month's target is not met and there is no royalty note.
        $this->assertSame(['rank_mo'], array_column($home['wallet']['pending']['rewards'], 'head'));

        $this->actingAs($finance)->onPortal('manage')->get('/treasury/partners/'.$leader->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('TreasuryPartner')
            ->where('pending.rewards.0.head', 'rank_mo')
            ->where('pending.rewards.0.amount', '5000.00'));
    }

    public function test_a_sub_dealer_holding_mo_gets_his_prize_line_and_one_with_no_rank_gets_none(): void
    {
        $sub = User::factory()->subDealer()->create(['status' => 'active']);
        $plain = User::factory()->subDealer()->create(['status' => 'active']);
        DealerRank::create(['user_id' => $sub->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        $rewards = fn (User $partner): array => $this->actingAs($partner)->onPortal('subdealer')->get('/my-money')->assertOk()->viewData('page')['props']['pending']['rewards'];

        // His own royalty target (৳30,000 of purchases) is not met yet, so the prize waits for that and there is no royalty note.
        $lines = $rewards($sub);
        $this->assertSame([['rank_mo', '5000.00', 'rank_prize_keep']], array_map(fn (array $line): array => [$line['head'], $line['amount'], $line['reason']], $lines));
        $this->assertSame([], $rewards($plain), 'A sub-dealer with no rank has no rank prize line.');
    }

    public function test_the_royalty_note_has_no_amount_and_only_a_rank_holder_gets_it(): void
    {
        $holder = $this->dealer();
        $plain = $this->dealer();
        DealerRank::create(['user_id' => $holder->id, 'current_rank' => 'sm', 'achieved_at' => now()]);
        DB::table('rank_incentive_awards')->insert([
            ['user_id' => $holder->id, 'rank' => 'mo', 'amount' => '5000.00', 'sources' => '{}', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $holder->id, 'rank' => 'am', 'amount' => '50000.00', 'sources' => '{}', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $holder->id, 'rank' => 'sm', 'amount' => '250000.00', 'sources' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $royalty = fn (User $partner): array => array_values(array_filter($this->myMoney($partner)['pending']['month_end'], fn (array $line): bool => $line['head'] === 'royalty'));
        $this->assertSame([], $royalty($holder), 'The royalty note waits for the month\'s target.');
        $this->purchase($holder, '30000.00', '60000.00');

        // Owner's rule of 2026-11-22: rank royalty is a month-end item (with the bonus and the area shares), not an earned prize.
        $this->assertSame([], $this->rewards($holder));
        $lines = $royalty($holder);
        $this->assertSame([['royalty', null, 'royalty_month_end', false]], array_map(fn (array $line): array => [$line['head'], $line['amount'], $line['reason'], $line['estimate']], $lines));
        $this->assertSame(__('cr21_income.head_royalty'), $lines[0]['what']);
        $this->assertSame([], $royalty($plain));
        $this->assertSame([], $this->rewards($plain));
    }

    public function test_a_sub_dealers_tour_fund_and_bike_prize_show_and_a_dealer_has_no_tour_line(): void
    {
        $sub = User::factory()->subDealer()->create(['status' => 'active']);
        $dealer = $this->dealer();
        $this->purchase($sub, '10000.00', '12000.00');
        RewardMilestoneProgress::create(['user_id' => $sub->id, 'milestone_type' => 'bike', 'target_amount' => '5000000.00', 'leg_cap_amount' => '800000.00', 'achieved' => true, 'achieved_at' => now()]);

        $lines = collect($this->actingAs($sub)->onPortal('subdealer')->get('/my-money')->assertOk()->viewData('page')['props']['pending']['rewards'])->keyBy('head');
        $this->assertSame(['bike', 'tour'], $lines->keys()->sort()->values()->all());
        $this->assertSame(['set_aside', 'tour_fund'], [$lines['tour']['state'], $lines['tour']['reason']]);
        $this->assertGreaterThan(0, (float) $lines['tour']['amount']);
        $this->assertSame(['pending', 'prize_office'], [$lines['bike']['state'], $lines['bike']['reason']]);
        $this->assertSame([], $this->rewards($dealer));
    }

    public function test_the_statement_words_a_rank_prize_a_refund_and_an_old_order_payment_in_plain_words(): void
    {
        $statement = app(PartnerWalletStatement::class);

        $this->assertSame(__('portal.jr_rank_prize', ['kind' => __('portal.settle_kind_rank_am')]), $statement->describe('commissions.settlement.12.7.rank_am', 'Commission Rank income · AM month closing 2026-09'));
        $this->assertStringNotContainsString('month closing', $statement->describe('commissions.settlement.12.7.rank_mo', 'Commission Rank income · MO month closing 2026-09'));
        $this->assertSame(__('orders_stock.jr_refund_to_account', ['number' => 'ORD-9']), $statement->describe('order.refund_to_account.'.Str::uuid(), 'অর্ডার ORD-9 এর ফেরতের টাকা পার্টনারের অ্যাকাউন্টে'));
        $this->assertSame(__('portal.jr_opening_collection', ['number' => 'OLD-3']), $statement->describe('opening.collection.5', 'Payment received for old order OLD-3'));
        // A month-end royalty keeps its month-closing sentence.
        $this->assertStringContainsString('2026-09', $statement->describe('commissions.settlement.royalty.2026-09.abc', __('portal.jr_commission_settlement', ['kind' => __('portal.settle_kind_royalty'), 'month' => '2026-09'])));
    }

    public function test_the_new_words_exist_in_both_languages(): void
    {
        foreach (['portal' => ['notice_rank_prize_title', 'notice_rank_prize_body', 'jr_rank_prize'], 'my_money' => ['rewards_heading', 'rewards_intro', 'reward_reason_rank_prize_office',
            'reward_reason_rank_prize_keep', 'reward_reason_prize_office', 'reward_reason_prize_review', 'reward_reason_royalty_month_end', 'reward_reason_tour_fund', 'status_set_aside',
            'month_end_heading', 'month_end_intro', 'month_end_intro_sub', 'month_end_no_amount', 'pending_reason_month_end_area', 'pending_reason_month_end_area_met',
            'pending_reason_salary_shared_month_end', 'pending_reason_royalty_month_end']] as $group => $keys) {
            foreach (['bn', 'en'] as $locale) {
                $words = require lang_path($locale.'/'.$group.'.php');
                foreach ($keys as $key) {
                    $this->assertNotSame('', trim((string) ($words[$key] ?? '')), "{$locale}/{$group}.{$key}");
                }
            }
        }
    }
}
