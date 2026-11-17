<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Finance\ReserveService;
use App\Domain\Finance\RewardFundCheck;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Reward and commission settings review (2026-11-17): the standard area targets and the held-share
 * limit are settings, the bike and car fund check, and held area shares that wait too long.
 */
class RewardSettingsReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    /** @param array<string, mixed> $parameters */
    private function saveArea(array $parameters): void
    {
        $this->actingAs($this->owner);
        app(CommissionPolicy::class)->update($this->owner, ['area_dealer_pool' => ['parameters' => $parameters]]);
    }

    public function test_the_standard_area_targets_and_the_held_limit_keep_their_old_values_by_default(): void
    {
        $this->assertSame('50000.00', CommissionPolicy::standardAreaQuota('division'));
        $this->assertSame('40000.00', CommissionPolicy::standardAreaQuota('district'));
        $this->assertSame('20000.00', CommissionPolicy::standardAreaQuota('upazila'));
        $this->assertSame('10000.00', CommissionPolicy::standardAreaQuota('union'));
        $this->assertSame('0.00', CommissionPolicy::standardAreaQuota('country'));
        $this->assertSame(3, CommissionPolicy::heldShareMonths());
    }

    public function test_a_rule_saved_before_these_settings_existed_still_returns_the_defaults(): void
    {
        $row = CommissionRuleParameter::getRule('area_dealer_pool');
        $parameters = $row->parameters;
        unset($parameters['held_share_months'], $parameters['division_monthly_quota']);
        $row->update(['parameters' => $parameters]);
        DB::table('commission_policy_revisions')->delete();

        $this->assertSame('50000.00', CommissionPolicy::standardAreaQuota('division'));
        $this->assertSame(3, CommissionPolicy::heldShareMonths());
    }

    public function test_the_owner_changes_them_and_the_change_is_audited(): void
    {
        $this->saveArea(['division_monthly_quota' => '60000.00', 'held_share_months' => '5']);

        $this->assertSame('60000.00', CommissionPolicy::standardAreaQuota('division'));
        $this->assertSame(5, CommissionPolicy::heldShareMonths());
        $changes = json_decode((string) DB::table('audit_events')->where('action', 'commissions.rules_updated')->latest('id')->value('metadata'), true)['changes']['area_dealer_pool'];
        $this->assertSame('50000.00', $changes['before']['parameters']['division_monthly_quota']);
        $this->assertSame('60000.00', $changes['after']['parameters']['division_monthly_quota']);
    }

    public function test_bad_values_are_refused(): void
    {
        foreach ([['held_share_months' => '121'], ['held_share_months' => '-1'], ['held_share_months' => '1.5'], ['union_monthly_quota' => '-5']] as $parameters) {
            try {
                $this->saveArea($parameters);
                $this->fail('Expected a validation error for '.json_encode($parameters));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('rules', $exception->errors());
            }
        }
        $this->assertSame(3, CommissionPolicy::heldShareMonths());
    }

    public function test_a_sales_target_of_zero_is_refused_but_zero_months_is_allowed(): void
    {
        $this->actingAs($this->owner);
        try {
            app(CommissionPolicy::class)->update($this->owner, ['car_bike_incentive' => ['parameters' => ['bike_target' => '0.00']]]);
            $this->fail('A zero target must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rules', $exception->errors());
        }
        $this->assertSame('5000000.00', app(CommissionPolicy::class)->snapshot()['rules']['car_bike_incentive']['parameters']['bike_target']);

        $this->saveArea(['held_share_months' => '0']);
        $this->assertSame(0, CommissionPolicy::heldShareMonths());
    }

    public function test_the_fund_check_with_the_company_defaults(): void
    {
        $check = app(RewardFundCheck::class)->check();

        $this->assertSame('5000000.00', $check['bike']['target']);
        $this->assertSame('2.00', $check['bike']['rate']);
        $this->assertSame('100000.00', $check['bike']['fund_at_target']);
        $this->assertSame('300000.00', $check['bike']['award']);
        $this->assertSame(RewardFundCheck::Short, $check['bike']['status']);
        $this->assertSame('200000.00', $check['bike']['shortfall']);
        $this->assertSame('15000000.00', $check['bike']['break_even_sales']);
        $this->assertSame('250000000.00', $check['car']['target']);
        $this->assertSame('5000000.00', $check['car']['fund_at_target']);
        $this->assertNull($check['car']['award']);
        $this->assertSame(RewardFundCheck::NotSet, $check['car']['status']);
        $this->assertNull($check['car']['shortfall']);
    }

    public function test_the_fund_check_follows_the_settings_and_says_when_the_fund_covers(): void
    {
        $this->actingAs($this->owner);
        app(CommissionPolicy::class)->update($this->owner, ['car_bike_incentive' => ['parameters' => ['bike_award_amount' => '100000.00', 'car_award_amount' => '6000000.00']]]);

        $check = app(RewardFundCheck::class)->check();

        $this->assertSame(RewardFundCheck::Covers, $check['bike']['status']);
        $this->assertSame(RewardFundCheck::Short, $check['car']['status']);
        $this->assertSame('1000000.00', $check['car']['shortfall']);
    }

    public function test_the_commission_page_shows_the_fund_check(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/commissions')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('data.reward_fund_check.bike.status', 'short')
                ->where('data.reward_fund_check.bike.shortfall', '200000.00')
                ->where('data.reward_fund_check.car.status', 'not_set'));
    }

    public function test_the_fund_check_words_exist_in_both_languages(): void
    {
        foreach (['fund_check_covers', 'fund_check_short', 'fund_check_award_missing'] as $key) {
            $this->assertNotSame('reward_settings.'.$key, __('reward_settings.'.$key, [], 'bn'));
            $this->assertNotSame('reward_settings.'.$key, __('reward_settings.'.$key, [], 'en'));
        }
        $this->assertNotSame('portal.cs_param_held_share_months', __('portal.cs_param_held_share_months', [], 'bn'));
    }

    // ---- held area shares that wait too long ----

    private function heldShare(): CommissionAllocation
    {
        $this->enableFinancialPosting();
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $division = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $dealer = User::factory()->inPlace($division)->create();
        // A target of zero is never missed: the share is held only because the dealer is suspended.
        AreaDealerAssignment::create(['user_id' => $dealer->id, 'level' => 'division', 'place_code_id' => $division->id,
            'division' => 'Rangpur', 'zone_name' => 'Rangpur', 'monthly_quota' => '0.00', 'is_active' => true]);
        $dealer->forceFill(['status' => 'suspended'])->save();
        $buyer = User::factory()->inPlace($division)->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        app(CommissionPosting::class)->process($order->fresh());

        $share = CommissionAllocation::where('order_id', $order->id)->where('category', 'area_division')->sole();
        $this->assertSame('held', $share->status);

        return $share;
    }

    public function test_a_share_still_held_after_the_limit_goes_to_the_company_fund_once(): void
    {
        $share = $this->heldShare();

        $this->travelTo(now()->setDate(2027, 1, 31)->setTime(1, 0));
        $this->assertSame('nothing_to_forfeit', app(ReserveService::class)->forfeitStaleAreaHolds()['status']);
        $this->assertSame('held', $share->fresh()->status);

        $this->travelTo(now()->setDate(2027, 2, 1)->setTime(1, 0));
        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertSame('forfeited', $share->fresh()->status);
        $profit = CommissionAllocation::where('metadata->source_allocation_id', $share->id)->sole();
        $this->assertSame('company_profit_fund', $profit->category);
        $this->assertSame((string) $share->amount, (string) $profit->amount);
        $this->assertSame('retained_reason_profit_held_too_long', $profit->metadata['reason_key']);
        $this->assertDatabaseHas('audit_events', ['action' => 'commission.stale_area_holds_released']);
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $share->user_id)->where('title', __('portal.notice_area_held_too_long_title'))->count());

        $this->assertSame('nothing_to_forfeit', app(ReserveService::class)->forfeitStaleAreaHolds()['status']);
        $this->assertSame(1, CommissionAllocation::where('metadata->source_allocation_id', $share->id)->count());
    }

    public function test_zero_months_means_never(): void
    {
        $share = $this->heldShare();
        $this->saveArea(['held_share_months' => '0']);

        $this->travelTo(now()->setDate(2028, 1, 1)->setTime(1, 0));

        $this->assertSame('off', app(ReserveService::class)->forfeitStaleAreaHolds()['status']);
        $this->assertSame('held', $share->fresh()->status);
    }

    public function test_the_owner_can_shorten_the_wait(): void
    {
        $share = $this->heldShare();
        $this->saveArea(['held_share_months' => '1']);

        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));
        $result = app(ReserveService::class)->forfeitStaleAreaHolds();

        $this->assertSame('forfeited', $result['status']);
        $this->assertSame('forfeited', $share->fresh()->status);
    }
}
