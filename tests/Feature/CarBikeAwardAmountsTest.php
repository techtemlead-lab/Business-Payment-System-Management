<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionService;
use App\Models\CommissionRuleParameter;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Owner decision: the bike and car award amounts are settings, and the car award stays blank until the owner fills it in. */
class CarBikeAwardAmountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->finance = User::factory()->staff('finance')->create();
    }

    /** @return array<string, mixed> */
    private function rewardRule(): array
    {
        return app(CommissionPolicy::class)->snapshot()['rules']['car_bike_incentive'];
    }

    /** @param array<string, string|null> $parameters */
    private function saveAwards(User $actor, string $portal, array $parameters): TestResponse
    {
        return $this->actingAs($actor)->onPortal($portal)
            ->post('/commands/commissions/rules', ['rules' => ['car_bike_incentive' => ['parameters' => $parameters]]]);
    }

    public function test_bike_award_starts_at_the_quotation_amount_and_car_award_starts_blank(): void
    {
        $parameters = $this->rewardRule()['parameters'];

        $this->assertSame('300000.00', $parameters['bike_award_amount']);
        $this->assertNull($parameters['car_award_amount']);
    }

    public function test_rules_saved_before_the_award_amounts_existed_still_have_them(): void
    {
        app(CommissionPolicy::class)->update($this->owner, ['direct_referral' => ['percentage' => '9.00']]);
        $revision = DB::table('commission_policy_revisions')->orderByDesc('id')->first();
        $rules = json_decode($revision->rules, true);
        unset($rules['car_bike_incentive']['parameters']['bike_award_amount'], $rules['car_bike_incentive']['parameters']['car_award_amount']);
        DB::table('commission_policy_revisions')->where('id', $revision->id)->update(['rules' => json_encode($rules)]);

        $parameters = $this->rewardRule()['parameters'];

        $this->assertSame('300000.00', $parameters['bike_award_amount']);
        $this->assertNull($parameters['car_award_amount']);
    }

    public function test_the_owner_sets_the_awards_and_the_change_is_audit_logged_without_touching_rates_or_targets(): void
    {
        $before = $this->rewardRule();

        $this->saveAwards($this->owner, 'sadmin', ['bike_award_amount' => '350000.00', 'car_award_amount' => '1500000.00'])
            ->assertRedirect()
            ->assertSessionHas('success', __('portal.flash_commission_policy_updated'));

        $after = $this->rewardRule();
        $this->assertSame('350000.00', $after['parameters']['bike_award_amount']);
        $this->assertSame('1500000.00', $after['parameters']['car_award_amount']);
        $this->assertSame((string) $before['percentage'], (string) $after['percentage']);
        foreach (['bike_target', 'bike_leg_cap', 'car_target', 'car_leg_cap'] as $name) {
            $this->assertSame($before['parameters'][$name], $after['parameters'][$name]);
        }

        $event = DB::table('audit_events')->where('action', 'commissions.rules_updated')->first();
        $changes = json_decode($event->metadata, true)['changes']['car_bike_incentive'];
        $this->assertSame($this->owner->id, (int) $event->actor_id);
        $this->assertNull($changes['before']['parameters']['car_award_amount']);
        $this->assertSame('1500000.00', $changes['after']['parameters']['car_award_amount']);
        $this->assertSame('300000.00', $changes['before']['parameters']['bike_award_amount']);
    }

    public function test_emptying_the_box_saves_the_award_as_blank(): void
    {
        $this->saveAwards($this->owner, 'sadmin', ['car_award_amount' => '1500000.00'])->assertSessionHasNoErrors();
        $this->saveAwards($this->owner, 'sadmin', ['car_award_amount' => ''])->assertSessionHasNoErrors();

        $this->assertNull($this->rewardRule()['parameters']['car_award_amount']);
    }

    public function test_an_award_amount_must_be_a_real_amount(): void
    {
        $this->saveAwards($this->owner, 'sadmin', ['car_award_amount' => '-5'])->assertSessionHasErrors('rules');

        $this->assertNull($this->rewardRule()['parameters']['car_award_amount']);
    }

    public function test_a_staff_change_waits_for_the_owner_like_other_commission_settings(): void
    {
        $this->saveAwards($this->finance, 'manage', ['car_award_amount' => '1500000.00'])
            ->assertRedirect()
            ->assertSessionHas('success', __('portal.flash_commission_change_pending'));

        $this->assertNull($this->rewardRule()['parameters']['car_award_amount']);
        $proposed = DB::table('audit_events')->where('action', 'commissions.rules_change_proposed')->first();
        $this->assertSame($this->finance->id, (int) $proposed->actor_id);
        $this->assertSame('1500000.00', json_decode($proposed->metadata, true)['changes']['car_bike_incentive']['after']['parameters']['car_award_amount']);
    }

    public function test_people_without_finance_rights_cannot_change_the_awards(): void
    {
        $this->saveAwards(User::factory()->staff('inventory')->create(), 'manage', ['car_award_amount' => '1500000.00'])->assertForbidden();

        $this->assertNull($this->rewardRule()['parameters']['car_award_amount']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'commissions.rules_change_proposed']);
    }

    public function test_settings_page_receives_the_amounts_with_the_car_award_blank(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/commissions')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('data.rules', function ($rules): bool {
                $parameters = collect($rules)->firstWhere('rule_key', 'car_bike_incentive')['parameters'];

                return $parameters['bike_award_amount'] === '300000.00'
                    && array_key_exists('car_award_amount', $parameters)
                    && $parameters['car_award_amount'] === null;
            }));
    }

    public function test_reaching_a_reward_target_pays_nothing_by_itself(): void
    {
        $this->enableFinancialPosting();
        $rule = CommissionRuleParameter::getRule('car_bike_incentive');
        $rule?->update(['is_active' => true, 'parameters' => [...($rule->parameters ?? []), 'bike_target' => '100.00', 'bike_leg_cap' => '100.00']]);
        $this->saveAwards($this->owner, 'sadmin', ['bike_award_amount' => '300000.00'])->assertSessionHasNoErrors();

        $leader = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($leader->id, null);
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $leader->id]);
        ReferralTreePath::attachUser($buyer->id, $leader->id);
        $order = Order::create([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'award-is-never-automatic'),
            'number' => 'ORD-AWARD-MANUAL',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '100.00',
            'paid' => '100.00',
        ]);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order->lines()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'mrp' => $product->mrp,
            'unit_price' => '100.00',
            'total' => '100.00',
        ]);

        app(CommissionService::class)->processOrder($order);

        $this->assertNotNull(RewardMilestoneProgress::where('user_id', $leader->id)->where('milestone_type', 'bike')->value('achieved_at'));
        $this->assertSame(0, DB::table('reserve_disbursements')->count());
        $this->assertSame(0, DB::table('commission_settlement_payouts')->where('kind', 'like', 'award_%')->count());
    }
}
