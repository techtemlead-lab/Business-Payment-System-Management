<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionQualification;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReferralTreeEarningsTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private User $child;

    private User $grandchild;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->root = User::factory()->dealer()->create(['name' => 'Root Dealer']);
        $this->child = User::factory()->dealer()->create(['name' => 'Child Dealer', 'sponsor_id' => $this->root->id]);
        $this->grandchild = User::factory()->dealer()->create(['name' => 'Grandchild Dealer', 'sponsor_id' => $this->child->id]);
        ReferralTreePath::attachUser($this->root->id, null);
        ReferralTreePath::attachUser($this->child->id, $this->root->id);
        ReferralTreePath::attachUser($this->grandchild->id, $this->child->id);

        $thisMonth = $this->order($this->grandchild, '1000.00', 'delivered', now());
        $this->allocation($thisMonth, $this->child, 'direct_referral', '50.00', 'available');
        $this->allocation($thisMonth, $this->root, 'd2d_gen2', '20.00', 'settled');
        $this->allocation($thisMonth, $this->root, 'd2d_gen2', '99.00', 'reversed');

        $lastYear = $this->order($this->child, '700.00', 'delivered', now()->subYear());
        $this->allocation($lastYear, $this->root, 'direct_referral', '35.00', 'settled');

        $this->order($this->grandchild, '400.00', 'submitted', now());
    }

    public function test_a_dealer_sees_what_each_member_bought_and_what_they_earned_from_them(): void
    {
        $this->actingAs($this->root)->onPortal('dealer')->get('/network')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.dealers', function ($dealers): bool {
                $nodes = collect($dealers)->keyBy('id');
                $grandchild = $nodes[$this->grandchild->id];
                $child = $nodes[$this->child->id];

                $this->assertSame('1000.00', $grandchild['sales_total'], 'Only completed orders count as sales.');
                $this->assertSame('1000.00', $grandchild['sales_month']);
                $this->assertSame('700.00', $child['sales_total']);
                $this->assertSame('50.00', $grandchild['sponsor_earned']);
                $this->assertSame('20.00', $grandchild['viewer_earned'], 'Reversed commission is left out.');
                $this->assertSame([['category' => 'd2d_gen2', 'amount' => '20.00']], $grandchild['viewer_earned_by_type']);

                $this->assertSame('0.00', $child['sales_month'], 'Last year\'s order is not this month\'s sale.');
                $this->assertSame('35.00', $child['sponsor_earned']);
                $this->assertSame('35.00', $child['viewer_earned']);

                return true;
            }));
    }

    public function test_a_dealer_does_not_see_members_or_earnings_outside_their_own_tree(): void
    {
        $outsider = User::factory()->dealer()->create(['name' => 'Outside Dealer']);
        ReferralTreePath::attachUser($outsider->id, null);

        $this->actingAs($this->child)->onPortal('dealer')->get('/network')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.dealers', function ($dealers) use ($outsider): bool {
                $ids = collect($dealers)->pluck('id')->all();

                $this->assertNotContains($this->root->id, $ids, 'The upline stays hidden.');
                $this->assertNotContains($outsider->id, $ids);
                $this->assertSame('50.00', collect($dealers)->firstWhere('id', $this->grandchild->id)['viewer_earned']);

                return true;
            }));
    }

    public function test_staff_see_what_each_referrer_earned_without_a_personal_earning_column(): void
    {
        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/network-tree')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.dealers', function ($dealers): bool {
                $grandchild = collect($dealers)->firstWhere('id', $this->grandchild->id);

                $this->assertSame('50.00', $grandchild['sponsor_earned']);
                $this->assertSame('1000.00', $grandchild['sales_month']);
                $this->assertArrayNotHasKey('viewer_earned', $grandchild);

                return true;
            }));
    }

    public function test_the_tree_shows_the_same_monthly_volume_that_decides_salary(): void
    {
        $month = CommissionQualification::currentMonth();
        $thisMonth = Order::query()->where('user_id', $this->grandchild->id)->where('status', 'delivered')->firstOrFail();
        $this->batch($thisMonth, $month, '1000.00', '100.00');
        // Ordered last month but delivered (and counted) this month.
        $lateDelivery = $this->order($this->child, '300.00', 'delivered', now()->subMonthNoOverflow()->startOfMonth());
        $this->batch($lateDelivery, $month, '300.00', '0.00');
        $lastYear = Order::query()->where('user_id', $this->child->id)->where('total', '700.00')->firstOrFail();
        $this->batch($lastYear, now()->subYear()->format('Y-m'), '700.00', '0.00');
        $expected = app(CommissionQualification::class)->monthlyVolumesFor([$this->grandchild->id, $this->child->id], $month);

        $this->actingAs($this->root)->onPortal('dealer')->get('/network')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.dealers', function ($dealers) use ($expected): bool {
                $nodes = collect($dealers)->keyBy('id');
                $grandchild = $nodes[$this->grandchild->id];
                $child = $nodes[$this->child->id];

                $this->assertSame('900.00', $grandchild['qualifying_volume_month'], 'Refunds come off the salary volume.');
                $this->assertSame(Money::decimal($expected[$this->grandchild->id]), $grandchild['qualifying_volume_month']);
                $this->assertSame('1000.00', $grandchild['sales_month']);
                $this->assertSame('300.00', $child['qualifying_volume_month'], 'Counted in the month it was delivered.');
                $this->assertSame(Money::decimal($expected[$this->child->id]), $child['qualifying_volume_month']);
                $this->assertSame('0.00', $nodes[$this->root->id]['qualifying_volume_month']);

                return true;
            }));
    }

    private function batch(Order $order, string $month, string $cash, string $refunded): void
    {
        DB::table('commission_batches')->insert([
            'order_id' => $order->id,
            'account_type' => 'dealer',
            'period_month' => $month,
            'cash_basis' => $cash,
            'mrp_basis' => $cash,
            'company_share' => $cash,
            'total_allocated' => '0.00',
            'unallocated' => '0.00',
            'refunded_amount' => $refunded,
            'policy' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(User $buyer, string $total, string $status, $createdAt): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => $total,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $createdAt]);

        return $order;
    }

    private function allocation(Order $order, User $earner, string $category, string $amount, string $status): void
    {
        DB::table('commission_allocations')->insert([
            'order_id' => $order->id,
            'user_id' => $earner->id,
            'category' => $category,
            'rate_percentage' => '5.00',
            'cash_basis' => $order->total,
            'amount' => $amount,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
