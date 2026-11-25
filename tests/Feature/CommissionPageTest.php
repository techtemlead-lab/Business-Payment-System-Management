<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPageModel;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Finance\Percentage;
use App\Http\Controllers\CommissionPageController;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The commission page shows the live commission rules. Every percent on it must equal the percent
 * the order posting pays with, saving goes through the engine's own rule update, and nobody gets more
 * than they had before.
 */
class CommissionPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.financial_activation' => false]);
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->finance = User::factory()->staff('finance')->create();
    }

    /** @return array<string, mixed> */
    private function pageProps(User $viewer, string $portal = 'manage', string $path = '/commissions'): array
    {
        $response = $this->actingAs($viewer)->onPortal($portal)->get($path)->assertOk();

        return $response->viewData('page')['props']['data']['commission_page'];
    }

    /** @return array<string, array<string, mixed>> */
    private function liveRules(): array
    {
        return app(CommissionPolicy::class)->snapshot(false)['rules'];
    }

    /** @return list<array{string}> */
    public static function everyHead(): array
    {
        return array_map(fn (string $key): array => [$key], [...CommissionPolicy::Keys, ...CommissionPageModel::SubDealerKeys]);
    }

    /** @return list<array{string}> */
    public static function splitHeads(): array
    {
        return [['d2d_multitier'], ['area_dealer_pool'], ['rank_royalty'], ['car_bike_incentive']];
    }

    private function change(User $actor, array $rules, string $portal = 'manage')
    {
        return $this->actingAs($actor)->onPortal($portal)->postJson('/commands/commissions/rules', ['rules' => $rules]);
    }

    #[DataProvider('everyHead')]
    public function test_each_commission_shows_the_percent_the_engine_pays_with(string $key): void
    {
        $head = collect($this->pageProps($this->finance)['heads'])->firstWhere('key', $key);
        $rules = $this->liveRules();
        $engine = CommissionPolicy::rate($rules, $key)->decimal();

        $this->assertNotNull($head);
        $this->assertSame($engine, $head['sub_dealer_pct']);
        $this->assertSame(in_array($key, CommissionPageModel::SubDealerKeys, true) ? null : $engine, $head['dealer_pct']);
        $this->assertSame($rules[$key]['category'], $head['kind']);
        $this->assertSame((int) $rules[$key]['version'], $head['version']);
    }

    #[DataProvider('everyHead')]
    public function test_a_changed_rate_shows_on_the_page_and_a_switched_off_commission_shows_zero(string $key): void
    {
        $rules = $this->liveRules();
        // Take the money from the same commission, so the total stays inside the limit.
        DB::table('commission_rule_parameters')->where('rule_key', $key)->update(['is_active' => false]);

        $head = collect($this->pageProps($this->finance)['heads'])->firstWhere('key', $key);

        $this->assertFalse($head['is_active']);
        $this->assertSame('0.00', $head['sub_dealer_pct']);
        $this->assertSame('0.00', CommissionPolicy::rate($this->liveRules(), $key)->decimal());
        $this->assertSame((string) $rules[$key]['percentage'], $head['percentage']);
    }

    #[DataProvider('splitHeads')]
    public function test_the_parts_of_a_commission_add_up_to_the_commission(string $key): void
    {
        $head = collect($this->pageProps($this->finance)['heads'])->firstWhere('key', $key);

        $sum = array_sum(array_map(fn (array $part): int => Percentage::from($part['pct'])->basisPoints, $head['children']));
        $this->assertGreaterThan(1, count($head['children']));
        $this->assertSame(Percentage::from($head['percentage'])->basisPoints, $sum);
        $this->assertTrue($head['children_match']);
        $this->assertSame($head['percentage'], $head['children_total']);
    }

    public function test_the_bike_and_car_parts_are_the_pools_the_engine_uses(): void
    {
        $this->change($this->owner, ['car_bike_incentive' => ['parameters' => ['bike_pool_share_pct' => '62.50']]], 'sadmin')->assertRedirect();

        $head = collect($this->pageProps($this->owner, 'sadmin')['heads'])->firstWhere('key', 'car_bike_incentive');
        $pools = CommissionPolicy::vehiclePoolRates($this->liveRules());

        $this->assertSame($pools['bike']->decimal(), $head['children'][0]['pct']);
        $this->assertSame($pools['car']->decimal(), $head['children'][1]['pct']);
        $this->assertSame('1.25', $head['children'][0]['pct']);
        $this->assertSame('0.75', $head['children'][1]['pct']);
    }

    public function test_a_sub_dealer_has_three_more_commissions_and_the_totals_are_the_engine_limits(): void
    {
        $page = $this->pageProps($this->finance);
        $subOnly = collect($page['heads'])->filter(fn (array $head): bool => $head['dealer_pct'] === null)->pluck('key')->all();

        $this->assertSame(CommissionPageModel::SubDealerKeys, $subOnly);
        $this->assertSame('40.00', $page['totals']['dealer_pct']);
        $this->assertSame('50.00', $page['totals']['sub_dealer_pct']);
        $this->assertSame('40.00', $page['totals']['dealer_cap_pct']);
        $this->assertSame('50.00', $page['totals']['sub_dealer_cap_pct']);
        $this->assertSame(CommissionPageModel::DealerCapBasisPoints, 4000);
        $this->assertSame(CommissionPageModel::SubDealerCapBasisPoints, 5000);
    }

    public function test_the_limits_on_the_page_are_the_limits_the_engine_refuses_at(): void
    {
        // The default rates sit exactly on both limits, so one more hundredth of a percent is refused.
        $this->change($this->owner, ['direct_referral' => ['percentage' => '10.01']], 'sadmin')->assertUnprocessable();
        $this->assertSame('10.00', $this->liveRules()['direct_referral']['percentage']);

        $this->change($this->owner, ['sub_dealer_tour' => ['percentage' => '3.01']], 'sadmin')->assertUnprocessable();
        $this->assertSame('3.00', $this->liveRules()['sub_dealer_tour']['percentage']);
    }

    public function test_the_conditions_are_the_live_parameters_and_follow_a_change(): void
    {
        $this->change($this->owner, ['dealer_monthly_salary' => ['parameters' => ['min_monthly_volume' => '12000.00']]], 'sadmin')->assertRedirect();
        $this->change($this->owner, ['rank_royalty' => ['parameters' => ['am_count' => 12]]], 'sadmin')->assertRedirect();

        $page = $this->pageProps($this->finance);
        $numbers = collect($page['conditions'])->flatMap(fn (array $condition): array => collect($condition['numbers'])->mapWithKeys(fn (array $number): array => [$condition['rule'].'.'.$number['param'] => $number['value']])->all())->all();

        $this->assertSame('12000.00', $numbers['dealer_monthly_salary.min_monthly_volume']);
        $this->assertSame('12', $numbers['rank_royalty.am_count']);
        foreach ($this->liveRules() as $ruleKey => $rule) {
            foreach ($rule['parameters'] as $name => $value) {
                if (array_key_exists($ruleKey.'.'.$name, $numbers) && $value !== null) {
                    $this->assertSame((string) $value, $numbers[$ruleKey.'.'.$name], $ruleKey.'.'.$name);
                }
            }
        }
    }

    public function test_every_number_the_engine_reads_for_rank_rewards_can_be_edited_on_the_page_and_a_change_reaches_the_engine(): void
    {
        $page = $this->pageProps($this->finance);
        $inConditions = collect($page['conditions'])->flatMap(fn (array $condition): array => array_map(fn (array $number): string => $condition['rule'].'.'.$number['param'], $condition['numbers']))->all();
        $inParts = collect($page['heads'])->flatMap(fn (array $head): array => array_map(fn (array $child): string => $head['key'].'.'.$child['param'], array_filter($head['children'] ?? [], fn (array $child): bool => $child['param'] !== null)))->all();
        $editable = [...$inConditions, ...$inParts];

        // Rank join targets and counts, team depth, the royalty target and parts, the one-time prizes, bike and car numbers,
        // the one monthly target by dealer kind and the sub-dealer salary steps. rank_maintenance (per-rank keep minimums and
        // grace days) is deliberately not on the page any more: it applies only to orders captured before the new rules.
        foreach (['rank_royalty', 'rank_incentive', 'car_bike_incentive', 'dealer_monthly_salary', 'sub_dealer_salary'] as $rule) {
            foreach ($this->liveRules()[$rule]['parameters'] as $name => $value) {
                $this->assertContains($rule.'.'.$name, $editable, "{$rule}.{$name} is read by the engine but cannot be edited on the page.");
            }
        }
        $this->assertNotContains('rank_maintenance.mo_min_monthly_volume', $editable);

        $this->change($this->owner, [
            'rank_royalty' => ['parameters' => ['mo_target' => '300000.00', 'am_count' => 11, 'sm_count' => 12, 'me_count' => 13, 'qualification_depth' => 4,
                'mo_share_pct' => '1.50', 'am_share_pct' => '1.00', 'sm_share_pct' => '0.75', 'me_share_pct' => '0.75']],
            'dealer_monthly_salary' => ['parameters' => ['union_target' => '31000.00', 'upazila_target' => '51000.00', 'district_target' => '101000.00', 'division_target' => '102000.00', 'min_monthly_volume' => '32000.00']],
            'rank_incentive' => ['parameters' => ['mo_bonus' => '6000.00', 'am_bonus' => '51000.00', 'sm_bonus' => '251000.00', 'me_bonus' => '301000.00']],
            'car_bike_incentive' => ['parameters' => ['bike_target' => '5100000.00', 'bike_leg_cap' => '810000.00', 'bike_award_amount' => '310000.00', 'car_target' => '251000000.00',
                'car_leg_cap' => '21000000.00', 'car_award_amount' => '600000.00', 'bike_pool_share_pct' => '60.00']],
        ], 'sadmin')->assertRedirect();

        $live = $this->liveRules();
        $this->assertSame(['300000.00', 11, 12, 13, 4, '1.50', '0.75'], [$live['rank_royalty']['parameters']['mo_target'], (int) $live['rank_royalty']['parameters']['am_count'], (int) $live['rank_royalty']['parameters']['sm_count'],
            (int) $live['rank_royalty']['parameters']['me_count'], (int) $live['rank_royalty']['parameters']['qualification_depth'], $live['rank_royalty']['parameters']['mo_share_pct'], $live['rank_royalty']['parameters']['sm_share_pct']]);
        $this->assertSame(['6000.00', '301000.00'], [$live['rank_incentive']['parameters']['mo_bonus'], $live['rank_incentive']['parameters']['me_bonus']]);
        $this->assertSame(['31000.00', '51000.00', '101000.00', '102000.00', '32000.00'], array_map(fn (string $name): string => $live['dealer_monthly_salary']['parameters'][$name], ['union_target', 'upazila_target', 'district_target', 'division_target', 'min_monthly_volume']));
        $this->assertSame(['310000.00', '600000.00', '60.00'], [$live['car_bike_incentive']['parameters']['bike_award_amount'], $live['car_bike_incentive']['parameters']['car_award_amount'], $live['car_bike_incentive']['parameters']['bike_pool_share_pct']]);

        // The page then shows what the engine now reads.
        $numbers = collect($this->pageProps($this->finance)['conditions'])->flatMap(fn (array $condition): array => collect($condition['numbers'])->mapWithKeys(fn (array $number): array => [$condition['rule'].'.'.$number['param'] => $number['value']])->all());
        $this->assertSame('300000.00', $numbers['rank_royalty.mo_target']);
        $this->assertSame('301000.00', $numbers['rank_incentive.me_bonus']);
        $this->assertSame('31000.00', $numbers['dealer_monthly_salary.union_target']);
        $this->assertSame('102000.00', $numbers['dealer_monthly_salary.division_target']);
    }

    public function test_conditions_are_shown_separately_for_dealers_and_sub_dealers(): void
    {
        $page = $this->pageProps($this->finance);
        $forSub = collect($page['conditions'])->filter(fn (array $condition): bool => in_array('sub_dealer', $condition['applies_to'], true))->pluck('id')->all();
        $forDealer = collect($page['conditions'])->filter(fn (array $condition): bool => in_array('dealer', $condition['applies_to'], true))->pluck('id')->all();

        $this->assertContains('sub_dealer_extras', $forSub);
        $this->assertNotContains('sub_dealer_extras', $forDealer);
        $this->assertContains('salary', $forDealer);
        $this->assertNotContains('salary', $forSub);
        $this->assertContains('bike', $forSub);
        $this->assertContains('bike', $forDealer);

        $rankKeep = collect($page['conditions'])->firstWhere('id', 'rank_keep');
        $this->assertSame(['sub_dealer'], $rankKeep['numbers'][0]['applies_to']);
        $subSalary = collect($page['conditions'])->firstWhere('id', 'sub_salary');
        $this->assertSame('3.00', $subSalary['facts']['max_pct']);
    }

    public function test_condition_updates_reject_zero_depth_and_accept_bangla_digits(): void
    {
        $this->change($this->owner, ['rank_royalty' => ['parameters' => ['qualification_depth' => 0]]], 'sadmin')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rules');

        $this->change($this->owner, [
            'dealer_monthly_salary' => ['parameters' => ['union_target' => '৩৫০০০']],
            'sub_dealer_salary' => ['parameters' => ['tier1_volume' => '১২০০০.৫০', 'tier1_pct' => '১.২৫']],
        ], 'sadmin')->assertRedirect();

        $live = $this->liveRules();
        $this->assertSame('35000.00', $live['dealer_monthly_salary']['parameters']['union_target']);
        $this->assertSame('12000.50', $live['sub_dealer_salary']['parameters']['tier1_volume']);
        $this->assertSame('1.25', $live['sub_dealer_salary']['parameters']['tier1_pct']);
    }

    public function test_a_staff_save_from_the_window_is_a_draft_and_only_the_owner_makes_it_active(): void
    {
        $rules = $this->liveRules();
        $payload = ['area_dealer_pool' => [
            'version' => $rules['area_dealer_pool']['version'], 'percentage' => '10.00',
            'parameters' => ['division_share_pct' => '3.00', 'union_share_pct' => '1.00'],
        ]];

        $this->change($this->finance, $payload)->assertRedirect();

        $draft = DB::table('commission_policy_schedules')->where('origin', 'rule_edit')->first();
        $this->assertSame('draft', $draft->status);
        $this->assertSame('2.00', $this->liveRules()['area_dealer_pool']['parameters']['division_share_pct'], 'The old split stays in force.');
        $this->assertSame(1, $this->pageProps($this->owner, 'sadmin')['workflow']['drafts_waiting']);
        $this->assertFalse($this->pageProps($this->finance)['workflow']['applies_at_once']);

        // Someone who is not the owner cannot make it active.
        $this->actingAs($this->finance)->postJson('/api/v1/operations/policy-schedules/'.$draft->id.'/review', ['decision' => 'approved', 'reason' => 'Looks fine to me'])->assertForbidden();
        $this->assertSame('2.00', $this->liveRules()['area_dealer_pool']['parameters']['division_share_pct']);

        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$draft->id.'/review', ['decision' => 'approved', 'reason' => 'Checked against the budget'])->assertOk();
        $live = $this->liveRules()['area_dealer_pool'];
        $this->assertSame('10.00', $live['percentage']);
        $this->assertSame('3.00', $live['parameters']['division_share_pct']);
        $this->assertSame('1.00', $live['parameters']['union_share_pct']);
        $this->assertSame((int) $rules['area_dealer_pool']['version'] + 1, (int) $live['version'], 'The rule moved up one version.');
        $this->assertSame(0, $this->pageProps($this->owner, 'sadmin')['workflow']['drafts_waiting']);
    }

    public function test_the_owner_save_is_active_at_once_and_bumps_the_version(): void
    {
        $before = $this->liveRules()['direct_referral'];

        $this->change($this->owner, ['direct_referral' => ['version' => $before['version'], 'percentage' => '9.50'], 'company_marketing' => ['percentage' => '2.50']], 'sadmin')->assertRedirect();

        $after = $this->liveRules();
        $this->assertSame('9.50', $after['direct_referral']['percentage']);
        $this->assertSame((int) $before['version'] + 1, (int) $after['direct_referral']['version']);
        $this->assertTrue($this->pageProps($this->owner, 'sadmin')['workflow']['applies_at_once']);
        $head = collect($this->pageProps($this->owner, 'sadmin')['heads'])->firstWhere('key', 'direct_referral');
        $this->assertSame('9.50', $head['dealer_pct']);
        $this->assertDatabaseHas('audit_events', ['action' => 'commissions.rules_updated', 'actor_id' => $this->owner->id]);
    }

    public function test_parts_that_do_not_add_up_to_the_commission_are_refused(): void
    {
        $this->change($this->owner, ['area_dealer_pool' => ['percentage' => '10.00', 'parameters' => ['division_share_pct' => '3.00']]], 'sadmin')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rules');
        $this->change($this->owner, ['d2d_multitier' => ['percentage' => '4.00']], 'sadmin')->assertUnprocessable()->assertJsonValidationErrors('rules');
        $this->change($this->owner, ['rank_royalty' => ['parameters' => ['mo_share_pct' => '2.00']]], 'sadmin')->assertUnprocessable();

        $this->assertSame('2.00', $this->liveRules()['area_dealer_pool']['parameters']['division_share_pct']);
    }

    public function test_a_change_made_on_an_out_of_date_page_is_refused(): void
    {
        $version = $this->liveRules()['direct_referral']['version'];
        $this->change($this->owner, ['direct_referral' => ['percentage' => '9.00']], 'sadmin')->assertRedirect();

        $this->change($this->owner, ['direct_referral' => ['version' => $version, 'percentage' => '8.00']], 'sadmin')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rules');
        $this->assertSame('9.00', $this->liveRules()['direct_referral']['percentage']);
    }

    public function test_a_rate_with_three_decimals_or_above_one_hundred_is_refused(): void
    {
        $this->change($this->owner, ['direct_referral' => ['percentage' => '5.555']], 'sadmin')->assertUnprocessable();
        $this->change($this->owner, ['direct_referral' => ['percentage' => '101']], 'sadmin')->assertUnprocessable();
        $this->assertSame('10.00', $this->liveRules()['direct_referral']['percentage']);
    }

    public function test_a_commission_the_engine_does_not_know_cannot_be_saved_as_a_live_rate(): void
    {
        $this->change($this->owner, ['brand_new_bonus' => ['percentage' => '1.00']], 'sadmin')->assertUnprocessable();
    }

    public function test_the_two_extra_actions_follow_the_finance_permission(): void
    {
        $orders = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['orders']]]);
        $viewer = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['finance'], 'action_permissions' => ['finance' => ['view' => true]]]]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        foreach ([$orders, $dealer] as $person) {
            $this->actingAs($person)->onPortal($person->role === 'dealer' ? 'dealer' : 'manage');
            $status = $this->postJson('/commands/commissions/split', ['buyer_type' => 'dealer', 'amount' => '1000'])->status();
            $this->assertContains($status, [403, 404], 'A partner or a person without the finance permission cannot use the calculator.');
        }
        $this->actingAs($orders)->onPortal('manage')->get('/commissions')->assertForbidden();
        $this->actingAs($orders)->onPortal('manage')->postJson('/commands/commissions/new-head', ['name' => 'X', 'kind' => 'other', 'dealer_pct' => '1', 'sub_dealer_pct' => '1'])->assertForbidden();

        // View-only finance staff see the page and the calculator but cannot change or add anything.
        $this->assertFalse($this->pageProps($viewer)['workflow']['can_edit']);
        $this->actingAs($viewer)->onPortal('manage')->postJson('/commands/commissions/split', ['buyer_type' => 'dealer', 'amount' => '1000'])->assertOk();
        $this->actingAs($viewer)->onPortal('manage')->postJson('/commands/commissions/new-head', ['name' => 'X', 'kind' => 'other', 'dealer_pct' => '1', 'sub_dealer_pct' => '1'])->assertForbidden();
        $this->change($viewer, ['direct_referral' => ['percentage' => '9.00']])->assertForbidden();

        $this->assertTrue($this->pageProps($this->finance)['workflow']['can_edit']);
    }

    public function test_a_new_commission_is_filed_as_a_written_draft_per_buyer_and_changes_no_live_rate(): void
    {
        $before = DB::table('commission_rule_parameters')->orderBy('id')->get()->all();

        $this->actingAs($this->finance)->onPortal('manage')
            ->post('/commands/commissions/new-head', ['name' => 'Festival bonus', 'kind' => 'rewards', 'dealer_pct' => '1.5', 'sub_dealer_pct' => '0'])
            ->assertRedirect()
            ->assertSessionHas('success', __('commission_page.flash_new_head_saved'));

        $drafts = DB::table('policy_drafts')->get();
        $this->assertCount(1, $drafts, 'Only the buyer with a rate above zero gets a draft.');
        $this->assertSame('dealer', $drafts[0]->account_type);
        $this->assertSame([['name' => 'Festival bonus', 'rate' => '1.50']], json_decode($drafts[0]->components, true));
        $this->assertSame(__('commission_page.new_head_draft_name', ['name' => 'Festival bonus', 'kind' => __('commission_page.kind_rewards')]), $drafts[0]->name);
        $this->assertEquals($before, DB::table('commission_rule_parameters')->orderBy('id')->get()->all());
        $this->assertDatabaseHas('audit_events', ['action' => 'policy.drafted', 'actor_id' => $this->finance->id]);

        $this->actingAs($this->finance)->onPortal('manage')
            ->post('/commands/commissions/new-head', ['name' => 'Both', 'kind' => 'other', 'dealer_pct' => '1', 'sub_dealer_pct' => '2'])->assertRedirect();
        $this->assertSame(3, DB::table('policy_drafts')->count());
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badNewCommissions(): array
    {
        return [
            'no name' => [['name' => '', 'kind' => 'other', 'dealer_pct' => '1', 'sub_dealer_pct' => '1'], 'name'],
            'unknown kind' => [['name' => 'A', 'kind' => 'magic', 'dealer_pct' => '1', 'sub_dealer_pct' => '1'], 'kind'],
            'three decimals' => [['name' => 'A', 'kind' => 'other', 'dealer_pct' => '1.234', 'sub_dealer_pct' => '1'], 'dealer_pct'],
            'above one hundred' => [['name' => 'A', 'kind' => 'other', 'dealer_pct' => '1', 'sub_dealer_pct' => '101'], 'sub_dealer_pct'],
            'no rate at all' => [['name' => 'A', 'kind' => 'other', 'dealer_pct' => '0', 'sub_dealer_pct' => '0'], 'dealer_pct'],
        ];
    }

    /** @param array<string, string> $input */
    #[DataProvider('badNewCommissions')]
    public function test_a_bad_new_commission_is_refused_with_a_clear_message(array $input, string $field): void
    {
        $response = $this->actingAs($this->finance)->onPortal('manage')->postJson('/commands/commissions/new-head', $input)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame(0, DB::table('policy_drafts')->count());
        if ($input['dealer_pct'] === '0') {
            $this->assertSame(__('commission_page.err_new_head_rate'), $response->json('errors.dealer_pct.0'));
        }
    }

    #[DataProvider('everyHead')]
    public function test_the_split_of_an_order_uses_the_engine_percent_for_every_commission(string $key): void
    {
        $this->change($this->owner, ['direct_referral' => ['percentage' => '9.37'], 'own_dealer_cashback' => ['percentage' => '5.63']], 'sadmin')->assertRedirect();
        $rules = $this->liveRules();
        $buyer = in_array($key, CommissionPageModel::SubDealerKeys, true) ? 'sub_dealer' : 'dealer';

        $response = $this->actingAs($this->finance)->onPortal('manage')->postJson('/commands/commissions/split', ['buyer_type' => $buyer, 'amount' => '12345.67'])->assertOk();
        $line = collect($response->json('lines'))->firstWhere('key', $key);

        $this->assertSame(CommissionPolicy::rate($rules, $key)->decimal(), $line['pct']);
        $this->assertSame(number_format(CommissionPolicy::rate($rules, $key)->of(1234567, 'half_up') / 100, 2, '.', ''), $line['amount']);
        foreach ($line['parts'] as $part) {
            $this->assertSame(number_format(Percentage::from($part['pct'])->of(1234567, 'half_up') / 100, 2, '.', ''), $part['amount']);
        }
    }

    public function test_the_split_adds_the_three_sub_dealer_lines_only_for_a_sub_dealer_and_shows_the_company_share(): void
    {
        $dealer = $this->actingAs($this->finance)->onPortal('manage')->postJson('/commands/commissions/split', ['buyer_type' => 'dealer', 'amount' => '10000', 'mrp' => '14000'])->assertOk();
        $sub = $this->actingAs($this->finance)->onPortal('manage')->postJson('/commands/commissions/split', ['buyer_type' => 'sub_dealer', 'amount' => '10000', 'mrp' => '14000'])->assertOk();

        $this->assertCount(9, $dealer->json('lines'));
        $this->assertCount(12, $sub->json('lines'));
        $this->assertSame('4000.00', $dealer->json('total_amount'));
        $this->assertSame('5000.00', $sub->json('total_amount'));
        $this->assertSame('4200.00', $dealer->json('company.amount'), 'The company keeps 30% of the MRP.');
        $this->assertSame('1800.00', $dealer->json('left_amount'));
        $this->assertSame('800.00', $sub->json('left_amount'));
    }

    public function test_the_split_says_so_when_the_price_is_too_low_to_pay_everything(): void
    {
        $response = $this->actingAs($this->finance)->onPortal('manage')->postJson('/commands/commissions/split', ['buyer_type' => 'dealer', 'amount' => '10000', 'mrp' => '30000'])->assertOk();

        $this->assertSame('-3000.00', $response->json('left_amount'));
    }

    public function test_a_bad_order_amount_is_refused_by_the_calculator(): void
    {
        $this->actingAs($this->finance)->onPortal('manage');
        $this->postJson('/commands/commissions/split', ['buyer_type' => 'dealer', 'amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/commands/commissions/split', ['buyer_type' => 'dealer', 'amount' => '10.555'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/commands/commissions/split', ['buyer_type' => 'partner', 'amount' => '10'])->assertUnprocessable()->assertJsonValidationErrors('buyer_type');
        $this->postJson('/commands/commissions/split', ['buyer_type' => 'dealer', 'amount' => '10', 'mrp' => '-5'])->assertUnprocessable()->assertJsonValidationErrors('mrp');
    }

    public function test_the_page_has_no_old_stats_strip_or_test_rates_screen(): void
    {
        $source = (string) file_get_contents(resource_path('js/components/commissionpage/CommissionPage.tsx'));
        $this->assertStringNotContainsString('cs-guard', $source);
        $this->assertStringNotContainsString('hub-progress', $source);
        $this->assertFileDoesNotExist(resource_path('js/components/CommissionStudio.tsx'));
        $this->assertFileDoesNotExist(resource_path('js/components/CompensationSimulator.tsx'));

        $this->actingAs($this->finance)->onPortal('manage')->get('/simulator')->assertOk()
            ->assertInertia(fn ($page) => $page->where('section', 'simulator'));
    }

    public function test_every_word_the_page_uses_exists_in_both_languages(): void
    {
        $bn = require lang_path('bn/commission_page.php');
        $en = require lang_path('en/commission_page.php');
        $this->assertSame(array_keys($en), array_keys($bn));

        foreach ([...CommissionPolicy::Keys, ...CommissionPageModel::SubDealerKeys] as $key) {
            $this->assertArrayHasKey('head_'.$key, $bn);
        }
        $page = $this->pageProps($this->finance);
        foreach ($page['heads'] as $head) {
            $this->assertArrayHasKey('kind_'.$head['kind'], $bn);
            if ($head['children'] !== []) {
                $this->assertArrayHasKey('parts_title_'.$head['key'], $bn);
            }
            foreach ($head['children'] as $part) {
                $this->assertArrayHasKey('part_'.$head['key'].'_'.$part['key'], $bn);
            }
        }
        foreach ($page['conditions'] as $condition) {
            $this->assertArrayHasKey('cond_'.$condition['id'].'_title', $bn);
            $this->assertArrayHasKey('cond_'.$condition['id'].'_text', $bn);
            foreach ($condition['numbers'] as $number) {
                $this->assertArrayHasKey('num_'.$number['param'], $bn);
                $this->assertArrayHasKey('controls_'.$number['controls'], $bn);
                // Every number in a sentence has its own placeholder, so the page never shows a bare ":name".
                // Two numbers are worded by the page itself (held months, waiting days).
                if (! in_array($number['param'], ['held_share_months', 'cooling_off_days'], true)) {
                    $this->assertStringContainsString(':'.$number['param'], $bn['cond_'.$condition['id'].'_text']);
                    $this->assertStringContainsString(':'.$number['param'], $en['cond_'.$condition['id'].'_text']);
                }
            }
        }
        foreach (CommissionPageController::NewHeadKinds as $kind) {
            $this->assertArrayHasKey('kind_'.$kind, $bn);
        }
    }
}
