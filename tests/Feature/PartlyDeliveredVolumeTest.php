<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\MixedOutcomeDecisions;
use App\Domain\Orders\OrderService;
use App\Models\CommissionRuleParameter;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An order of ৳10,000 where ৳6,000 arrived and ৳4,000 came back: monthly volume and bike and car
 * volume both count ৳6,000, never ৳10,000 and never ৳0.
 */
class PartlyDeliveredVolumeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $reviewer;

    private User $leader;

    private User $buyer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->reviewer = User::factory()->staff('finance')->create(['status' => 'active']);
        $this->leader = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($this->leader->id, null);
        $this->buyer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $this->leader->id]);
        ReferralTreePath::attachUser($this->buyer->id, $this->leader->id);
        $this->product = Product::factory()->create(['active' => true, 'mrp' => '3000.00', 'dealer_price' => '2000.00', 'stock' => 100]);
    }

    public function test_the_delivered_part_counts_in_monthly_and_in_bike_and_car_volume(): void
    {
        $order = $this->mixedOrder(3, 2);

        $this->decide($order, MixedOutcomeDecisions::DeliveredPart);

        $this->assertSame('6000.00', $this->monthly());
        foreach (['bike', 'car'] as $type) {
            $milestone = app(CommissionQualification::class)->milestone($this->leader->id, $type);
            $this->assertSame('6000.00', $milestone['raw_team_volume']);
            $this->assertSame('6000.00', $milestone['legs'][0]['raw_volume']);
        }
    }

    public function test_the_stored_progress_is_updated_when_the_delivered_part_posts(): void
    {
        $this->decide($this->mixedOrder(3, 2), MixedOutcomeDecisions::DeliveredPart);

        $this->assertSame('6000.00', (string) RewardMilestoneProgress::query()->where('user_id', $this->leader->id)->where('milestone_type', 'bike')->value('raw_team_volume'));
    }

    public function test_a_whole_order_and_a_delivered_part_add_up_correctly(): void
    {
        $this->fullyDelivered(5);
        $this->decide($this->mixedOrder(3, 2), MixedOutcomeDecisions::DeliveredPart);

        $this->assertSame('16000.00', $this->monthly());
        $this->assertSame('16000.00', $this->teamVolume());
    }

    public function test_choosing_no_commission_counts_in_neither_place(): void
    {
        $this->decide($this->mixedOrder(3, 2), MixedOutcomeDecisions::NoCommission);

        $this->assertSame('0.00', $this->monthly());
        $this->assertSame('0.00', $this->teamVolume());
    }

    public function test_nothing_counts_before_the_decision(): void
    {
        $this->mixedOrder(3, 2);

        $this->assertSame('0.00', $this->monthly());
        $this->assertSame('0.00', $this->teamVolume());
    }

    public function test_a_refund_comes_off_both_places(): void
    {
        $order = $this->mixedOrder(3, 2);
        $this->decide($order, MixedOutcomeDecisions::DeliveredPart);

        app(CommissionService::class)->reverseOrderPartial($order->fresh(), '1000.00', 'Part back', 'part-back', $this->owner->id);

        $this->assertSame('5000.00', $this->monthly());
        $this->assertSame('5000.00', $this->teamVolume());
    }

    public function test_a_bike_award_is_reached_on_the_delivered_part_alone(): void
    {
        $this->setBikeTarget('7000.00');
        $this->decide($this->mixedOrder(3, 2), MixedOutcomeDecisions::DeliveredPart);
        $this->assertFalse(app(CommissionQualification::class)->milestone($this->leader->id, 'bike')['achieved'], 'The ৳10,000 order total must not reach a ৳7,000 target.');

        $this->decide($this->mixedOrder(1, 1), MixedOutcomeDecisions::DeliveredPart);

        $this->assertTrue(app(CommissionQualification::class)->milestone($this->leader->id, 'bike')['achieved']);
    }

    public function test_a_reward_already_given_stays_given_and_no_money_moves(): void
    {
        $this->setBikeTarget('5000.00');
        $this->fullyDelivered(3);
        app(CommissionQualification::class)->refreshMilestones($this->leader->id);
        RewardMilestoneProgress::query()->where('user_id', $this->leader->id)->where('milestone_type', 'bike')->update(['claimed' => true, 'claimed_at' => now()]);

        $this->decide($this->mixedOrder(3, 2), MixedOutcomeDecisions::DeliveredPart);

        $row = RewardMilestoneProgress::query()->where('user_id', $this->leader->id)->where('milestone_type', 'bike')->firstOrFail();
        $this->assertTrue((bool) $row->claimed);
        $this->assertSame('12000.00', (string) $row->raw_team_volume);
        $this->assertSame(0, DB::table('reserve_disbursements')->where('user_id', $this->leader->id)->count());
    }

    public function test_rank_promotion_counts_only_the_delivered_part(): void
    {
        $this->setMoTarget('7000.00');
        $this->decide($this->mixedOrder(3, 2), MixedOutcomeDecisions::DeliveredPart);

        $rank = DealerRank::query()->where('user_id', $this->buyer->id)->firstOrFail();
        $this->assertSame('6000.00', (string) $rank->lifetime_purchase_total);
        $this->assertSame('dealer', $rank->current_rank, 'The ৳10,000 order total must not reach a ৳7,000 rank target.');

        $this->decide($this->mixedOrder(1, 1), MixedOutcomeDecisions::DeliveredPart);

        $rank->refresh();
        $this->assertSame('8000.00', (string) $rank->lifetime_purchase_total);
        $this->assertSame('mo', $rank->current_rank);
    }

    private function monthly(): string
    {
        return Money::decimal(app(CommissionQualification::class)->monthlyVolume($this->buyer->id, CommissionQualification::currentMonth()));
    }

    private function teamVolume(): string
    {
        return app(CommissionQualification::class)->milestone($this->leader->id, 'bike')['raw_team_volume'];
    }

    private function setBikeTarget(string $target): void
    {
        $rule = CommissionRuleParameter::getRule('car_bike_incentive');
        $rule?->update(['is_active' => true, 'parameters' => [...($rule->parameters ?? []), 'bike_target' => $target, 'bike_leg_cap' => '100000.00']]);
    }

    private function setMoTarget(string $target): void
    {
        $rule = CommissionRuleParameter::getRule('rank_royalty');
        $rule?->update(['is_active' => true, 'parameters' => [...($rule->parameters ?? []), 'mo_target' => $target]]);
    }

    private function decide(Order $order, string $choice): void
    {
        app(MixedOutcomeDecisions::class)->decide($this->reviewer, $order->fresh(), $choice, 'Dealer agreed on the phone');
    }

    private function fullyDelivered(int $quantity): Order
    {
        $order = $this->paidOrder($quantity);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh(), $this->owner->id);

        return $order->fresh();
    }

    private function paidOrder(int $quantity): Order
    {
        $order = app(OrderService::class)->create($this->buyer, ['items' => [['product_id' => $this->product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total, 'status' => 'approved']);

        return $order->fresh();
    }

    /** One parcel delivered and one received back, every unit sent, status as the courier flow leaves it. */
    private function mixedOrder(int $delivered, int $returned): Order
    {
        $order = $this->paidOrder($delivered + $returned);
        $line = $order->lines()->where('is_free', false)->firstOrFail();
        foreach (['delivered' => $delivered, 'returned' => $returned] as $status => $quantity) {
            DB::table('order_lines')->where('id', $line->id)->increment('dispatched', $quantity);
            DB::table('shipments')->insert(['order_id' => $order->id, 'courier' => 'Test', 'tracking' => 'T-'.Str::random(6), 'status' => $status, 'courier_status' => $status,
                'lines' => json_encode([['line_id' => $line->id, 'name' => 'Item', 'quantity' => $quantity, 'lots' => []]], JSON_THROW_ON_ERROR),
                'actor_id' => $this->owner->id, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('orders')->where('id', $order->id)->update(['status' => 'partly_delivered']);

        return $order->fresh();
    }
}
