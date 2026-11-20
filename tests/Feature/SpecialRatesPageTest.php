<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\PolicyScheduling;
use App\Domain\Commissions\RecipientFunding;
use App\Domain\Commissions\SpecialRateModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "One person's special rate": what the page shows must be what the engine uses, a special rate goes through the
 * engine's own schedule and approval, and the conditions the page lets the owner lift are exactly the switches the
 * engine reads. Nothing here changes how a commission is worked out.
 */
class SpecialRatesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.financial_activation' => false]);
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->finance = User::factory()->staff('finance')->create();
        $this->partner = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'name' => 'Rahim Uddin', 'dealer_code' => 'D-7001', 'phone' => '01712345678']);
    }

    /** @return array<string, mixed> */
    private function page(User $viewer, array $query = [], string $portal = 'manage'): array
    {
        $path = '/operations/policy-schedules'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->actingAs($viewer)->onPortal($portal)->getJson($path)->assertOk()->json('data');
    }

    private function propose(User $actor, string $scope, array $rules, array $extra = [])
    {
        return $this->actingAs($actor)->postJson('/api/v1/operations/policy-schedules', [
            'scope' => $scope, 'user_id' => $this->partner->id, 'rules' => $rules, 'effective_at' => now()->toIso8601String(),
            'reason' => 'Agreed with the owner', ...$extra,
        ]);
    }

    private function approve(int $id)
    {
        return $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked against the budget']);
    }

    /** @return list<array{string}> */
    public static function receiverCategories(): array
    {
        return array_map(fn (string $category): array => [$category], array_keys(SpecialRateModel::ReceiverLines));
    }

    #[DataProvider('receiverCategories')]
    public function test_the_general_rate_on_the_page_is_the_rate_the_posting_uses(string $category): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->postJson('/commands/commissions/rules', ['rules' => [
            'own_dealer_cashback' => ['percentage' => '6.00'], 'direct_referral' => ['percentage' => '9.00'],
            'd2d_multitier' => ['parameters' => ['gen2_pct' => '1.50', 'gen3_pct' => '1.50']],
            'area_dealer_pool' => ['parameters' => ['division_share_pct' => '3.00', 'union_share_pct' => '1.00']],
        ]])->assertRedirect();
        $rules = app(CommissionPolicy::class)->snapshot(false)['rules'];
        [$rule, $parameter] = SpecialRateModel::ReceiverLines[$category];
        $expected = $parameter === null ? CommissionPolicy::rate($rules, $rule)->decimal() : number_format((float) $rules[$rule]['parameters'][$parameter], 2, '.', '');

        $line = collect($this->page($this->finance)['special']['receiver_lines'])->firstWhere('category', $category);

        $this->assertSame($expected, $line['pct']);
    }

    public function test_a_person_is_found_by_name_code_or_phone_and_only_partners_are_listed(): void
    {
        User::factory()->create(['role' => 'sub_dealer', 'status' => 'active', 'name' => 'Karim Mia', 'dealer_code' => 'S-9001', 'phone' => '01899999999']);
        User::factory()->staff('employee')->create(['name' => 'Rahim Staff']);

        foreach (['Rahim', 'd-7001', '01712345678', '2345678'] as $query) {
            $names = collect($this->page($this->finance, ['q' => $query])['partners'])->pluck('name')->all();
            $this->assertSame(['Rahim Uddin'], $names, 'Search for '.$query);
        }
        $row = $this->page($this->finance, ['q' => 'Rahim'])['partners'][0];
        $this->assertSame(['D-7001', 'dealer', '01712345678'], [$row['dealer_code'], $row['role'], $row['phone']]);
        $this->assertSame([], $this->page($this->finance, ['q' => 'nobody-here'])['partners']);
    }

    public function test_choosing_a_person_shows_the_person_and_nothing_special_yet(): void
    {
        $special = $this->page($this->finance, ['person' => $this->partner->id])['special'];

        $this->assertSame($this->partner->id, $special['person']['id']);
        $this->assertSame('Rahim Uddin', $special['person']['name']);
        $this->assertNull($special['receiver_current']);
        $this->assertNull($special['buyer_current']);
        $this->assertSame([], $special['schedules']);
        $this->assertNull($this->page($this->finance)['special']['person']);
        // A staff account is not a person for a special rate.
        $this->assertNull($this->page($this->finance, ['person' => $this->finance->id])['special']['person']);
    }

    public function test_a_receiver_rate_the_owner_makes_active_is_what_the_engine_reads(): void
    {
        $id = $this->propose($this->owner, 'recipient', [
            'direct_referral' => ['percentage' => '12.00', 'funding_mode' => 'company'],
            'd2d_gen2' => ['percentage' => '1.00'],
        ])->assertOk()->json('data.id');

        $special = $this->page($this->owner, ['person' => $this->partner->id], 'sadmin')['special'];
        $engine = app(CommissionPolicy::class)->recipientOverrides([$this->partner->id])[$this->partner->id];

        $this->assertSame($engine['rates'], $special['receiver_current']['rates']);
        $this->assertSame($id, $special['receiver_current']['schedule_id']);
        $this->assertSame('12.00', $special['receiver_current']['rates']['direct_referral']['percentage']);
        $row = $special['schedules'][0];
        $this->assertSame(['active', 'recipient', $id], [$row['state'], $row['scope'], $row['id']]);
        $this->assertCount(2, $row['lines']);
        $this->assertStringContainsString('12', $row['lines'][0].$row['lines'][1]);
        // The general rates did not move.
        $this->assertSame('10.00', collect($special['receiver_lines'])->firstWhere('category', 'direct_referral')['pct']);
    }

    public function test_a_buyer_plan_keeps_the_parts_and_the_sums_of_the_engine(): void
    {
        $this->propose($this->owner, 'buyer', ['area_dealer_pool' => ['percentage' => '10.00', 'parameters' => ['division_share_pct' => '3.00', 'union_share_pct' => '1.00']]])->assertOk();
        // Parts that do not add up to the head are refused, as on the commission page.
        $this->propose($this->owner, 'buyer', ['area_dealer_pool' => ['percentage' => '10.00', 'parameters' => ['division_share_pct' => '3.00', 'union_share_pct' => '5.00']]])->assertUnprocessable();

        $special = $this->page($this->owner, ['person' => $this->partner->id], 'sadmin')['special'];
        $head = collect($special['buyer_current']['heads'])->firstWhere('key', 'area_dealer_pool');

        $this->assertSame('3.00', collect($head['children'])->firstWhere('key', 'division')['pct']);
        $this->assertSame('1.00', collect($head['children'])->firstWhere('key', 'union')['pct']);
        $this->assertTrue($head['children_match']);
        $general = collect($special['heads'])->firstWhere('key', 'area_dealer_pool');
        $this->assertSame('2.00', collect($general['children'])->firstWhere('key', 'division')['pct'], 'The general plan is untouched.');
        $this->assertSame('40.00', $special['totals']['dealer_cap_pct']);
    }

    public function test_a_new_buyer_plan_is_worked_out_from_the_current_one_so_the_page_writes_the_whole_plan(): void
    {
        $this->propose($this->owner, 'buyer', ['area_dealer_pool' => ['percentage' => '10.00', 'parameters' => ['division_share_pct' => '3.00', 'union_share_pct' => '1.00']]])->assertOk();
        // Only the changed value is sent: the earlier special union share (1) would stay, and the person keeps a plan that is not what the card showed.
        $this->propose($this->owner, 'buyer', ['area_dealer_pool' => ['percentage' => '10.00', 'parameters' => ['division_share_pct' => '2.00']]])->assertUnprocessable();

        // The whole plan is sent: every part is written, so the union share really goes back to the general rate.
        $this->propose($this->owner, 'buyer', ['area_dealer_pool' => ['percentage' => '10.00', 'parameters' => [
            'management_share_pct' => '2.00', 'division_share_pct' => '2.00', 'district_share_pct' => '2.00', 'upazila_share_pct' => '2.00', 'union_share_pct' => '2.00',
        ]]])->assertOk();

        $head = collect($this->page($this->owner, ['person' => $this->partner->id], 'sadmin')['special']['buyer_current']['heads'])->firstWhere('key', 'area_dealer_pool');
        $this->assertSame('2.00', collect($head['children'])->firstWhere('key', 'union')['pct']);
        $this->assertSame('2.00', collect($head['children'])->firstWhere('key', 'division')['pct']);
    }

    public function test_the_pool_limits_still_refuse_a_special_plan_over_forty_percent(): void
    {
        $this->propose($this->owner, 'buyer', ['direct_referral' => ['percentage' => '10.01']])->assertUnprocessable();
        $this->assertSame([], $this->page($this->owner, ['person' => $this->partner->id], 'sadmin')['special']['schedules']);
    }

    public function test_a_staff_proposal_is_a_draft_that_only_the_owner_can_make_active(): void
    {
        $id = $this->propose($this->finance, 'recipient', ['direct_referral' => ['percentage' => '9.00']])->assertOk()->json('data.id');

        $asFinance = collect($this->page($this->finance, ['person' => $this->partner->id])['special']['schedules'])->firstWhere('id', $id);
        $this->assertSame('draft', $asFinance['state']);
        $this->assertFalse($asFinance['can_review']);
        $this->assertTrue($asFinance['can_cancel'], 'The person who proposed it can take it back.');
        $this->assertFalse($asFinance['can_end']);
        $this->assertNull($this->page($this->finance, ['person' => $this->partner->id])['special']['receiver_current'], 'A draft changes nothing yet.');

        $asOwner = collect($this->page($this->owner, ['person' => $this->partner->id], 'sadmin')['special']['schedules'])->firstWhere('id', $id);
        $this->assertTrue($asOwner['can_review']);

        $this->actingAs($this->finance)->postJson('/api/v1/operations/policy-schedules/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Looks fine to me'])->assertForbidden();
        $this->approve($id)->assertOk();

        $after = collect($this->page($this->owner, ['person' => $this->partner->id], 'sadmin')['special']['schedules'])->firstWhere('id', $id);
        $this->assertSame('active', $after['state']);
        $this->assertTrue($after['can_end']);
        $this->assertFalse($after['can_review']);
        $this->assertSame('9.00', $this->page($this->owner, ['person' => $this->partner->id], 'sadmin')['special']['receiver_current']['rates']['direct_referral']['percentage']);
    }

    public function test_everyone_waiting_for_the_owner_is_listed_before_a_person_is_chosen(): void
    {
        $id = $this->propose($this->finance, 'recipient', ['direct_referral' => ['percentage' => '9.00']])->assertOk()->json('data.id');

        $rows = $this->page($this->owner, [], 'sadmin')['tables'][0]['page']['data'];

        $this->assertSame('policy_schedules', $this->page($this->owner, [], 'sadmin')['tables'][0]['key']);
        $this->assertSame($id, $rows[0]['id']);
        $this->assertSame('draft', $rows[0]['status']);
    }

    public function test_the_owner_can_change_the_end_of_a_special_rate_and_stop_it_now(): void
    {
        $id = $this->propose($this->owner, 'recipient', ['direct_referral' => ['percentage' => '9.00']])->assertOk()->json('data.id');
        $end = CarbonImmutable::now()->addDays(10);

        $this->actingAs($this->finance)->postJson('/api/v1/operations/policy-schedules/'.$id.'/end', ['mode' => 'set_end', 'expires_at' => $end->toIso8601String(), 'reason' => 'Agreed with the owner'])->assertForbidden();

        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$id.'/end', ['mode' => 'set_end', 'expires_at' => $end->toIso8601String(), 'reason' => 'Agreed with the owner'])->assertOk();
        $this->assertEqualsWithDelta($end->timestamp, CarbonImmutable::parse(DB::table('commission_policy_schedules')->where('id', $id)->value('expires_at'))->timestamp, 1);
        $this->assertArrayHasKey($this->partner->id, app(CommissionPolicy::class)->recipientOverrides([$this->partner->id]), 'It still works until the end time.');

        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$id.'/end', ['mode' => 'stop_now', 'reason' => 'The agreement is over'])->assertOk();

        $this->assertSame([], app(CommissionPolicy::class)->recipientOverrides([$this->partner->id]), 'The engine no longer uses it.');
        $row = collect($this->page($this->owner, ['person' => $this->partner->id], 'sadmin')['special']['schedules'])->firstWhere('id', $id);
        $this->assertSame('ended', $row['state']);
        $this->assertFalse($row['can_end']);
        $this->assertDatabaseHas('audit_events', ['action' => 'policy_schedule.end_changed', 'actor_id' => $this->owner->id, 'subject' => (string) $id]);
        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$id.'/end', ['mode' => 'stop_now', 'reason' => 'Again'])->assertUnprocessable();
    }

    public function test_the_end_can_be_cleared_and_a_rate_that_has_not_started_is_cancelled_when_stopped(): void
    {
        $id = $this->propose($this->owner, 'recipient', ['direct_referral' => ['percentage' => '9.00']], ['expires_at' => now()->addDays(5)->toIso8601String()])->assertOk()->json('data.id');
        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$id.'/end', ['mode' => 'set_end', 'reason' => 'No date given'])->assertUnprocessable();
        $this->assertNotNull(DB::table('commission_policy_schedules')->where('id', $id)->value('expires_at'));
        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$id.'/end', ['mode' => 'open_end', 'reason' => 'Open ended now'])->assertOk();
        $this->assertNull(DB::table('commission_policy_schedules')->where('id', $id)->value('expires_at'));

        $later = $this->propose($this->owner, 'buyer', ['direct_referral' => ['percentage' => '9.00']], ['effective_at' => now()->addDays(3)->toIso8601String()])->assertOk()->json('data.id');
        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$later.'/end', ['mode' => 'stop_now', 'reason' => 'Plan changed'])->assertOk();

        $this->assertSame('cancelled', DB::table('commission_policy_schedules')->where('id', $later)->value('status'));
    }

    public function test_a_rule_change_made_on_the_commission_page_cannot_be_ended_here(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->postJson('/commands/commissions/rules', ['rules' => ['direct_referral' => ['percentage' => '9.00'], 'company_marketing' => ['percentage' => '3.00']]])->assertRedirect();
        $this->actingAs($this->finance)->onPortal('manage')->postJson('/commands/commissions/rules', ['rules' => ['direct_referral' => ['percentage' => '8.00'], 'company_marketing' => ['percentage' => '4.00']]])->assertRedirect();
        $draft = DB::table('commission_policy_schedules')->where('origin', 'rule_edit')->value('id');
        $this->approve($draft)->assertOk();

        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$draft.'/end', ['mode' => 'stop_now', 'reason' => 'Not allowed'])->assertUnprocessable();
    }

    public function test_only_the_owner_sets_conditions_and_they_are_the_switches_the_engine_reads(): void
    {
        $special = $this->page($this->owner, ['person' => $this->partner->id], 'sadmin')['special'];
        $this->assertSame(['salary', 'rank_join', 'rank_keep', 'rank_bonus'], array_keys($special['waivable']));
        $this->assertSame(['unconditional_salary', 'unconditional_rank_royalty', 'unconditional_rank_royalty', 'unconditional_rank_incentive'], array_values($special['waivable']));
        $this->assertTrue($special['workflow']['can_set_conditions']);
        $this->assertFalse($special['controls']['unconditional_salary']);
        $this->assertFalse($this->page($this->finance, ['person' => $this->partner->id])['special']['workflow']['can_set_conditions']);

        $this->actingAs($this->finance)->onPortal('manage')->post('/commands/accounts/'.$this->partner->id.'/benefit-controls', ['unconditional_salary' => true, 'reason' => 'Agreed with the owner'])->assertForbidden();
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts/'.$this->partner->id.'/benefit-controls', [
            'unconditional_salary' => true, 'unconditional_rank_royalty' => true, 'forced_royalty_rank' => 'am', 'unconditional_rank_incentive' => false, 'reason' => 'Agreed with the owner',
        ])->assertRedirect();

        $after = $this->page($this->finance, ['person' => $this->partner->id])['special']['controls'];
        $engine = PartnerBenefitControls::for($this->partner->fresh());
        $this->assertTrue($after['unconditional_salary']);
        $this->assertSame('am', $after['forced_royalty_rank']);
        $this->assertSame($engine['unconditional_salary'], $after['unconditional_salary']);
        $this->assertTrue(PartnerBenefitControls::waivesSalaryVolume($this->partner->fresh()));
        $this->assertSame('am', PartnerBenefitControls::forcedRoyaltyRank($this->partner->fresh()));
    }

    public function test_every_condition_is_listed_with_the_general_value_and_the_ones_that_cannot_be_lifted_are_known(): void
    {
        $ids = collect($this->page($this->finance, ['person' => $this->partner->id])['special']['conditions'])->pluck('id')->all();
        $waivable = array_keys(SpecialRateModel::Waivable);

        foreach (['area', 'salary', 'rank_join', 'rank_keep', 'rank_bonus', 'bike', 'car', 'cooling_off', 'sub_dealer_extras'] as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertSame(['area', 'bike', 'car', 'cooling_off', 'sub_dealer_extras'], array_values(array_diff($ids, $waivable)), 'These conditions have no per-person switch in the engine.');
    }

    public function test_a_partner_or_a_person_without_the_finance_permission_cannot_open_the_page(): void
    {
        $orders = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['orders']]]);

        $this->actingAs($orders)->onPortal('manage')->getJson('/operations/policy-schedules')->assertForbidden();
        $status = $this->actingAs($this->partner)->onPortal('dealer')->getJson('/operations/policy-schedules')->status();
        $this->assertContains($status, [403, 404]);
    }

    public function test_the_lines_of_a_schedule_are_plain_words_with_old_and_new_values(): void
    {
        app()->setLocale('en');
        $model = app(SpecialRateModel::class);

        $receiver = $model->lines('recipient', ['direct_referral' => ['before' => [], 'after' => ['percentage' => '12.00', 'funding_mode' => 'company']]]);
        $buyer = $model->lines('buyer', ['area_dealer_pool' => ['before' => ['percentage' => '10.00', 'parameters' => ['division_share_pct' => '2.00']], 'after' => ['percentage' => '11.00', 'parameters' => ['division_share_pct' => '3.00']]]]);

        $this->assertSame(['Direct Referral: 12% (From the Company Share)'], $receiver);
        $this->assertSame(['Area dealer income: 10% → 11%', 'Area dealer income · Division: 2.00 → 3.00'], $buyer);
        app()->setLocale('bn');
        $this->assertSame(['সরাসরি রেফারেল: ১২% (কোম্পানির অংশ থেকে)'], $model->lines('recipient', ['direct_referral' => ['before' => [], 'after' => ['percentage' => '12.00', 'funding_mode' => 'company']]]));
    }

    public function test_every_word_of_the_page_exists_in_both_languages(): void
    {
        $bn = require lang_path('bn/special_rates.php');
        $en = require lang_path('en/special_rates.php');
        $this->assertSame(array_keys($en), array_keys($bn));

        foreach (array_keys(SpecialRateModel::ReceiverLines) as $category) {
            $this->assertArrayHasKey('receiver_'.$category, $bn);
        }
        foreach (RecipientFunding::Sources as $source) {
            $this->assertArrayHasKey('source_'.$source, $bn, $source);
        }
        foreach (['draft', 'active', 'upcoming', 'ended', 'expired', 'rejected', 'cancelled'] as $state) {
            $this->assertArrayHasKey('state_'.$state, $bn);
        }
        foreach (['recipient', 'buyer', 'global'] as $scope) {
            $this->assertArrayHasKey('scope_'.$scope, $bn);
        }
        foreach (['approved', 'rejected', 'cancelled', 'end'] as $action) {
            $this->assertArrayHasKey('modal_'.$action, $bn);
            $this->assertArrayHasKey('do_'.$action, $bn);
        }
        foreach (['company', 'allocations'] as $mode) {
            $this->assertArrayHasKey('funding_'.$mode, $bn);
        }
        foreach (['receiver', 'buyer', 'conditions'] as $tab) {
            $this->assertArrayHasKey('tab_'.$tab, $bn);
        }
    }

    public function test_ending_uses_the_same_owner_rule_as_approving(): void
    {
        $this->assertTrue(CommissionPolicy::approves($this->owner));
        $this->assertFalse(CommissionPolicy::approves($this->finance));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(PolicyScheduling::class)->end($this->finance, 1, null, 'Not the owner');
    }
}
