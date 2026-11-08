<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Staff sections follow the top-bar branch switcher: the owner and all-branch
 * staff see every branch by default, a picked branch filters the page, and
 * branch staff only ever see the branch they are assigned to.
 */
class BranchScopedSectionsTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $dhaka;

    private CompanyBranch $rajshahi;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dhaka = CompanyBranch::factory()->create(['code' => 'DHK-01', 'name' => 'Dhaka Hub']);
        $this->rajshahi = CompanyBranch::factory()->create(['code' => 'RAJ-01', 'name' => 'Rajshahi Hub']);
        $this->partner = User::factory()->dealer()->create();
    }

    public function test_owner_on_all_branches_sees_orders_of_every_branch_and_unassigned_ones(): void
    {
        $orders = $this->ordersPerBranch();

        $this->ownerOnAll()->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === $this->sorted([$orders['dhaka'], $orders['rajshahi'], $orders['none']]))
            ->where('data.order_totals.orders', 3));
    }

    public function test_owner_on_a_picked_branch_sees_only_that_branch(): void
    {
        $orders = $this->ordersPerBranch();
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->dhaka->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'branch')
            ->where('data.branch_scope.code', 'DHK-01')
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === [$orders['dhaka']])
            ->where('data.order_status_counts', ['dispatched' => 1])
            ->where('data.order_totals.orders', 1));

        $this->ownerOnAll()->get('/orders?branch_id='.$this->rajshahi->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'RAJ-01')
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === [$orders['rajshahi']]));
    }

    public function test_branch_staff_see_only_their_branch_even_when_they_ask_for_another(): void
    {
        $orders = $this->ordersPerBranch();
        $staff = $this->branchStaff($this->dhaka);

        $this->actingAs($staff)->onPortal('manage')
            ->withSession([BranchOperations::SessionView => 'all'])
            ->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'DHK-01')
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === [$orders['dhaka']]));

        $this->actingAs($staff)->onPortal('manage')->get('/orders?branch_id='.$this->rajshahi->id)->assertForbidden();
        $this->actingAs($staff)->onPortal('manage')->get('/orders?all_branches=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === [$orders['dhaka']]));
    }

    public function test_staff_with_no_branch_see_no_orders(): void
    {
        $this->ordersPerBranch();
        $staff = User::factory()->staff('support')->create();

        $this->actingAs($staff)->onPortal('manage')->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'branch')
            ->where('data.branch_scope.id', null)
            ->where('data.orders.data', []));
    }

    public function test_finance_staff_see_every_branch(): void
    {
        $orders = $this->ordersPerBranch();
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('manage')->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === $this->sorted([$orders['dhaka'], $orders['rajshahi'], $orders['none']])));
    }

    public function test_partner_order_pages_have_no_branch_chip(): void
    {
        $this->ordersPerBranch();

        $this->actingAs($this->partner)->onPortal('dealer')->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('data.branch_scope')
            ->where('data.orders.total', 3));
    }

    private function ownerOnAll(): self
    {
        return $this->actingAs(User::factory()->staff()->create())
            ->onPortal('sadmin')
            ->withSession([BranchOperations::SessionView => 'all']);
    }

    private function branchStaff(CompanyBranch $branch, string $role = 'support'): User
    {
        $staff = User::factory()->staff($role)->create(['active_branch_id' => $branch->id]);
        BranchStaffAssignment::query()->create(['branch_id' => $branch->id, 'user_id' => $staff->id, 'duty' => 'storekeeper', 'is_primary' => true]);

        return $staff;
    }

    /**
     * One sent order at each branch and one not yet given a branch.
     *
     * @return array{dhaka: string, rajshahi: string, none: string}
     */
    private function ordersPerBranch(string $status = 'dispatched'): array
    {
        return [
            'dhaka' => $this->order($this->dhaka->id, $status)->number,
            'rajshahi' => $this->order($this->rajshahi->id, $status)->number,
            'none' => $this->order(null, 'submitted')->number,
        ];
    }

    private function order(?int $branchId, string $status): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $this->partner->id,
            'status' => $status,
            'total' => '500.00',
            'paid' => '0.00',
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        $order->forceFill(['fulfillment_branch_id' => $branchId])->save();

        return $order;
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function numbers(iterable $rows): array
    {
        return $this->sorted(collect($rows)->pluck('number')->all());
    }

    /**
     * @param  list<string>  $numbers
     * @return list<string>
     */
    private function sorted(array $numbers): array
    {
        sort($numbers);

        return array_values($numbers);
    }
}
