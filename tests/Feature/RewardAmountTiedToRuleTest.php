<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\ReserveService;
use App\Models\CommissionRuleParameter;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Owner's decision: a bike or car reward pays exactly the amount set in the reward rule. */
class RewardAmountTiedToRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CommissionRuleSeeder::class);
        $this->finance = User::factory()->staff('finance')->create();
        $this->enableFinancialPosting();
        $rule = CommissionRuleParameter::getRule('car_bike_incentive');
        $rule?->update(['is_active' => true, 'parameters' => [...($rule->parameters ?? []), 'bike_target' => '100.00', 'bike_leg_cap' => '100.00', 'car_target' => '100.00', 'car_leg_cap' => '100.00', 'bike_award_amount' => '300000.00']]);

        $this->leader = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($this->leader->id, null);
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $this->leader->id]);
        ReferralTreePath::attachUser($buyer->id, $this->leader->id);
        $order = Order::create(['idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'reward'), 'number' => 'ORD-REWARD-RULE',
            'user_id' => $buyer->id, 'status' => 'delivered', 'total' => '100.00', 'paid' => '100.00']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'mrp' => $product->mrp, 'unit_price' => '100.00', 'total' => '100.00']);
        app(CommissionService::class)->processOrder($order);
    }

    /** @return array<string, mixed> */
    private function request(string $program, string $amount): array
    {
        return ['program' => $program, 'user_id' => $this->leader->id, 'amount' => $amount, 'request_key' => (string) Str::uuid(), 'reason' => 'Target reached this month', 'payee_name' => null];
    }

    public function test_a_bike_reward_for_any_other_amount_is_refused_with_a_plain_reason(): void
    {
        try {
            app(ReserveService::class)->request($this->finance, $this->request('bike', '10.00'));
            $this->fail('A reward that differs from the rule amount must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('portal.err_reserve_rule_amount', ['program' => __('portal.prc_reserve_program_bike'), 'amount' => '3,00,000.00'])], $exception->errors()['amount']);
        }

        $this->assertDatabaseCount('reserve_disbursements', 0);
    }

    public function test_a_bike_reward_for_exactly_the_rule_amount_is_accepted(): void
    {
        $id = app(ReserveService::class)->request($this->finance, $this->request('bike', '300000'));

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'program' => 'bike', 'amount' => '300000.00']);
    }

    public function test_a_reward_whose_rule_amount_is_blank_is_refused(): void
    {
        $this->assertNull(CommissionRuleParameter::getRule('car_bike_incentive')?->parameters['car_award_amount'] ?? null);

        try {
            app(ReserveService::class)->request($this->finance, $this->request('car', '500.00'));
            $this->fail('A car reward with no rule amount must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('portal.err_reserve_rule_amount_blank', ['program' => __('portal.prc_reserve_program_car')])], $exception->errors()['amount']);
        }
    }

    public function test_an_older_waiting_reward_with_another_amount_cannot_be_approved(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $id = DB::table('reserve_disbursements')->insertGetId(['program' => 'bike', 'user_id' => $this->leader->id, 'amount' => '10.00', 'status' => 'requested', 'request_key' => (string) Str::uuid(),
            'reason' => 'Older request', 'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);

        try {
            app(ReserveService::class)->review($owner, $id, 'approved', 'Approve the older request now', true);
            $this->fail('An older reward with the wrong amount must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('portal.err_reserve_rule_amount', ['program' => __('portal.prc_reserve_program_bike'), 'amount' => '3,00,000.00'])], $exception->errors()['amount'] ?? null);
        }

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'requested']);
    }

    public function test_a_rank_reward_keeps_its_own_amount(): void
    {
        $id = app(ReserveService::class)->request($this->finance, $this->request('additional_rank', '10.00'));

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'amount' => '10.00']);
    }

    public function test_the_reasons_say_the_same_in_both_languages(): void
    {
        foreach (['err_reserve_rule_amount', 'err_reserve_rule_amount_blank', 'pbc_owner_required'] as $key) {
            foreach (['en', 'bn'] as $locale) {
                $this->assertNotSame('portal.'.$key, __('portal.'.$key, [], $locale));
            }
        }
    }
}
