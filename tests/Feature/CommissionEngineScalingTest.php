<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionReversal;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CommissionEngineScalingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $this->seed(CommissionRuleSeeder::class);
    }

    private function partner(?User $sponsor, string $role = 'dealer'): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'sponsor_id' => $sponsor?->id]);
        ReferralTreePath::attachUser($user->id, $sponsor?->id);

        return $user;
    }

    private function postedOrder(User $buyer, string $total = '100.00'): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => $total]);
        app(CommissionPosting::class)->process($order);

        return $order;
    }

    /** @return array<int, string> */
    private function bikeVolumes(array $userIds): array
    {
        return RewardMilestoneProgress::query()->whereIn('user_id', $userIds)->where('milestone_type', 'bike')
            ->pluck('raw_team_volume', 'user_id')->map(fn ($volume): string => (string) $volume)->all();
    }

    public function test_refund_recounts_upline_milestones_after_the_refund_commits(): void
    {
        $this->enableFinancialPosting();
        $top = $this->partner(null);
        $middle = $this->partner($top);
        $buyer = $this->partner($middle);
        $order = $this->postedOrder($buyer, '100.00');
        $this->assertSame([$top->id => '100.00', $middle->id => '100.00'], $this->bikeVolumes([$top->id, $middle->id]));

        DB::transaction(function () use ($order, $top, $middle): void {
            app(CommissionReversal::class)->refund($order, 4000, 'Part of the order returned', 'SCALE-REFUND');
            $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id, 'refunded_amount' => '40.00']);
            $this->assertSame([$top->id => '100.00', $middle->id => '100.00'], $this->bikeVolumes([$top->id, $middle->id]),
                'Milestones must not be recounted while the refund still holds the money lock.');
        });

        $this->assertSame([$top->id => '60.00', $middle->id => '60.00'], $this->bikeVolumes([$top->id, $middle->id]));
        foreach ([$top, $middle] as $leader) {
            $expected = app(CommissionQualification::class)->milestone($leader->id, 'car');
            $this->assertDatabaseHas('reward_milestone_progress', ['user_id' => $leader->id, 'milestone_type' => 'car',
                'raw_team_volume' => $expected['raw_team_volume'], 'qualifying_volume' => $expected['qualifying_volume']]);
        }
    }

    public function test_attaching_a_deep_chain_reads_the_sponsors_in_one_query(): void
    {
        $chain = [$this->partner(null)];
        foreach (range(1, 7) as $level) {
            $chain[] = $this->partner(end($chain));
        }
        $leaf = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => end($chain)->id]);

        DB::enableQueryLog();
        ReferralTreePath::attachUser($leaf->id, $leaf->sponsor_id);
        $userReads = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from\s+"?users"?(\s|$)/i', $query['query']) === 1)->count();
        DB::disableQueryLog();

        $this->assertSame(1, $userReads, 'The sponsor chain should be read in one query, not one per level.');
        $depths = ReferralTreePath::where('descendant_id', $leaf->id)->orderBy('depth')->pluck('depth', 'ancestor_id')->all();
        $expected = [$leaf->id => 0];
        foreach (array_reverse($chain) as $index => $ancestor) {
            $expected[$ancestor->id] = $index + 1;
        }
        $this->assertSame($expected, $depths);
    }

    public function test_sponsor_chain_guards_are_unchanged(): void
    {
        $top = $this->partner(null);
        $middle = $this->partner($top);
        $bottom = $this->partner($middle);

        try {
            ReferralTreePath::reparentUser($top->id, $bottom->id);
            $this->fail('A loop in the sponsor chain must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(__('portal.err_tree_circular_hierarchy'), $exception->getMessage());
        }

        $this->expectException(ModelNotFoundException::class);
        ReferralTreePath::attachUser($bottom->id, 999999);
    }

    public function test_reparenting_moves_the_whole_branch_with_the_new_chain(): void
    {
        $oldTop = $this->partner(null);
        $newTop = $this->partner(null);
        $newMiddle = $this->partner($newTop);
        $branch = $this->partner($oldTop);
        $child = $this->partner($branch);

        ReferralTreePath::reparentUser($branch->id, $newMiddle->id);

        $this->assertSame(
            [$child->id => 0, $branch->id => 1, $newMiddle->id => 2, $newTop->id => 3],
            ReferralTreePath::where('descendant_id', $child->id)->orderBy('depth')->pluck('depth', 'ancestor_id')->all(),
        );
        $this->assertFalse(ReferralTreePath::isDescendant($oldTop->id, $child->id));
    }
}
