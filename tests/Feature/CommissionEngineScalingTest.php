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
            ->orderBy('user_id')->pluck('raw_team_volume', 'user_id')->map(fn ($volume): string => (string) $volume)->all();
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

    public function test_batch_milestone_refresh_stores_what_the_single_leader_refresh_stores(): void
    {
        $this->enableFinancialPosting();
        $top = $this->partner(null);
        $left = $this->partner($top);
        $right = $this->partner($top);
        $leftChild = $this->partner($left);
        $grandChild = $this->partner($leftChild);
        $sub = $this->partner($right, 'sub_dealer');
        $this->postedOrder($left, '100.00');
        $this->postedOrder($grandChild, '100.00');
        $this->postedOrder($sub, '120.00');
        $refunded = $this->postedOrder($leftChild, '100.00');
        app(CommissionReversal::class)->refund($refunded, 2500, 'Part returned', 'BATCH-REFUND');

        $leaders = [$top->id, $left->id, $right->id, $leftChild->id, $grandChild->id];
        $expected = [];
        foreach ($leaders as $leaderId) {
            foreach (['bike', 'car'] as $type) {
                $expected[$leaderId][$type] = app(CommissionQualification::class)->milestone($leaderId, $type);
            }
        }
        RewardMilestoneProgress::query()->delete();

        app(CommissionQualification::class)->refreshMilestonesFor([...$leaders, $top->id]);

        foreach ($leaders as $leaderId) {
            foreach (['bike', 'car'] as $type) {
                $row = RewardMilestoneProgress::query()->where('user_id', $leaderId)->where('milestone_type', $type)->sole();
                $this->assertSame($expected[$leaderId][$type]['raw_team_volume'], (string) $row->raw_team_volume);
                $this->assertSame($expected[$leaderId][$type]['qualifying_volume'], (string) $row->qualifying_volume);
                $this->assertSame($expected[$leaderId][$type]['legs'], $row->leg_breakdown);
            }
        }
        $this->assertSame('395.00', $expected[$top->id]['bike']['raw_team_volume']);
        $this->assertSame('175.00', $expected[$left->id]['bike']['raw_team_volume']);
    }

    public function test_milestone_refresh_reads_team_volumes_once_however_deep_the_chain(): void
    {
        $this->enableFinancialPosting();
        $chain = [$this->partner(null)];
        for ($level = 0; $level < 12; $level++) {
            $chain[] = $this->partner(end($chain));
        }
        $this->postedOrder(end($chain), '100.00');
        $leaders = array_map(fn (User $user): int => $user->id, array_slice($chain, 0, -1));

        $volumeScans = 0;
        DB::listen(function ($query) use (&$volumeScans): void {
            if (str_contains($query->sql, 'FROM orders') || str_contains($query->sql, 'from "orders"')) {
                $volumeScans++;
            }
        });
        app(CommissionQualification::class)->refreshMilestonesFor($leaders);

        $this->assertSame(1, $volumeScans);
        $this->assertSame(array_fill(0, 12, '100.00'), array_values($this->bikeVolumes($leaders)));
    }

    public function test_wallet_credit_debt_lookup_uses_the_partial_deficit_index(): void
    {
        $definition = DB::table('pg_indexes')->where('tablename', 'commission_allocations')
            ->where('indexname', 'commission_allocations_user_unrecovered_deficit_index')->value('indexdef');

        $this->assertNotNull($definition);
        $this->assertStringContainsString("(metadata ->> 'unrecovered_deficit'::text) IS NOT NULL", (string) $definition);
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
