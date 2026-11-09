<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dhaka = CompanyBranch::factory()->create(['code' => 'DHK-01', 'name' => 'Dhaka Hub']);
        $this->rajshahi = CompanyBranch::factory()->create(['code' => 'RAJ-01', 'name' => 'Rajshahi Hub']);
        $this->partner = User::factory()->dealer()->create();
        $this->product = Product::factory()->create();
    }

    /**
     * Order-list pages: path, status of the orders that have a branch, status of the
     * order with no branch yet, and the permissions a branch staff member needs.
     *
     * @return array<string, array{string, string, string, list<string>}>
     */
    public static function orderListPages(): array
    {
        return [
            'orders' => ['/orders', 'dispatched', 'submitted', ['orders']],
            'deliveries' => ['/deliveries', 'partially_dispatched', 'approved', ['orders']],
            'overview' => ['/overview', 'dispatched', 'submitted', ['orders']],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    #[DataProvider('orderListPages')]
    public function test_owner_on_all_branches_sees_every_branch_and_unassigned_orders(string $path, string $status, string $unassignedStatus, array $permissions): void
    {
        $orders = $this->ordersPerBranch($status, $unassignedStatus);

        $this->ownerOnAll()->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === $this->sorted(array_values($orders))));
    }

    /**
     * @param  list<string>  $permissions
     */
    #[DataProvider('orderListPages')]
    public function test_owner_on_a_picked_branch_sees_only_that_branch(string $path, string $status, string $unassignedStatus, array $permissions): void
    {
        $orders = $this->ordersPerBranch($status, $unassignedStatus);
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->dhaka->id]);

        $this->flushSession();
        $this->actingAs($owner)->onPortal('sadmin')->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'branch')
            ->where('data.branch_scope.code', 'DHK-01')
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === [$orders['dhaka']]));

        $this->ownerOnAll()->get($path.'?branch_id='.$this->rajshahi->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'RAJ-01')
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === [$orders['rajshahi']]));
    }

    /**
     * @param  list<string>  $permissions
     */
    #[DataProvider('orderListPages')]
    public function test_branch_staff_see_only_their_branch_even_when_they_ask_for_another(string $path, string $status, string $unassignedStatus, array $permissions): void
    {
        $orders = $this->ordersPerBranch($status, $unassignedStatus);
        $staff = $this->branchStaff($this->dhaka, $permissions);

        $this->actingAs($staff)->onPortal('manage')
            ->withSession([BranchOperations::SessionView => 'all'])
            ->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'DHK-01')
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === [$orders['dhaka']]));

        $this->actingAs($staff)->onPortal('manage')->get($path.'?branch_id='.$this->rajshahi->id)->assertForbidden();
        $this->actingAs($staff)->onPortal('manage')->get($path.'?all_branches=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === [$orders['dhaka']]));
    }

    /**
     * @param  list<string>  $permissions
     */
    #[DataProvider('orderListPages')]
    public function test_finance_staff_see_every_branch(string $path, string $status, string $unassignedStatus, array $permissions): void
    {
        $orders = $this->ordersPerBranch($status, $unassignedStatus);
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('manage')->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === $this->sorted(array_values($orders))));
    }

    public function test_order_tabs_and_totals_follow_the_picked_branch(): void
    {
        $this->ordersPerBranch('dispatched', 'submitted');
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->dhaka->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.order_status_counts', ['dispatched' => 1])
            ->where('data.order_totals.orders', 1));
        $this->ownerOnAll()->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.order_totals.orders', 3));
    }

    public function test_delivery_summary_follows_the_picked_branch(): void
    {
        $this->ordersPerBranch('partially_dispatched', 'approved');
        $staff = $this->branchStaff($this->dhaka, ['orders']);

        $this->actingAs($staff)->onPortal('manage')->get('/deliveries')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.delivery_summary.orders', 1)
            ->where('data.delivery_summary.units', 1));
    }

    public function test_overview_cards_and_work_queue_follow_the_picked_branch(): void
    {
        $orders = $this->ordersPerBranch('dispatched', 'submitted');
        foreach ($orders as $number) {
            $this->claimFor($number);
        }
        $this->withdrawalsPerBranch();
        $queueCount = fn (array $queue, string $key): int => (int) collect($queue)->firstWhere('key', $key)['count'];
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->dhaka->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/overview')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'DHK-01')
            ->where('data.stats.order_count', 1)
            ->where('data.admin_home.month.orders', 1)
            ->where('data.admin_home.work_queue', fn ($queue) => $queueCount($queue->all(), 'orders_to_review') === 0
                && $queueCount($queue->all(), 'payments_to_check') === 1
                && $queueCount($queue->all(), 'withdrawals_to_review') === 1)
            // The Dhaka partner and the partner on no branch: only the first counts.
            ->where('data.stats.accounts', 1));

        $this->ownerOnAll()->get('/overview')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.stats.order_count', 3)
            ->where('data.admin_home.work_queue', fn ($queue) => $queueCount($queue->all(), 'orders_to_review') === 1
                && $queueCount($queue->all(), 'payments_to_check') === 3
                && $queueCount($queue->all(), 'withdrawals_to_review') === 3)
            ->where('data.stats.accounts', 4));
    }

    public function test_staff_with_no_branch_see_no_orders(): void
    {
        $this->ordersPerBranch('dispatched', 'submitted');
        $staff = User::factory()->staff('support')->create();

        $this->actingAs($staff)->onPortal('manage')->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'branch')
            ->where('data.branch_scope.id', null)
            ->where('data.orders.data', []));
    }

    public function test_partner_order_pages_have_no_branch_chip(): void
    {
        $this->ordersPerBranch('dispatched', 'submitted');

        $this->actingAs($this->partner)->onPortal('dealer')->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('data.branch_scope')
            ->where('data.orders.total', 3));
    }

    public function test_top_sellers_rank_only_the_picked_branch_partners(): void
    {
        $rajshahiPlace = $this->placeRajshahiBranch();
        $sellers = [
            'Dhaka Seller' => User::factory()->dealer()->create(['name' => 'Dhaka Seller', 'active_branch_id' => $this->dhaka->id, 'district' => 'Sylhet']),
            'Rajshahi Seller' => User::factory()->dealer()->inPlace($rajshahiPlace)->create(['name' => 'Rajshahi Seller']),
            'No Branch Seller' => User::factory()->dealer()->create(['name' => 'No Branch Seller', 'district' => 'Sylhet']),
        ];
        foreach (array_values($sellers) as $index => $seller) {
            $this->partner = $seller;
            $this->order(null, 'dispatched')->forceFill(['total' => (string) (1000 - $index * 100).'.00'])->save();
        }
        $names = fn ($rows): array => collect($rows)->pluck('name')->all();

        $this->ownerOnAll()->get('/top-sellers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.top_sellers.national', fn ($rows) => $names($rows) === ['Dhaka Seller', 'Rajshahi Seller', 'No Branch Seller']));

        $this->flushSession();
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->rajshahi->id]);
        $this->actingAs($owner)->onPortal('sadmin')->get('/top-sellers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'RAJ-01')
            ->where('data.top_sellers.national', fn ($rows) => $names($rows) === ['Rajshahi Seller']
                && collect($rows)->first()['rank'] === 1));

        $staff = $this->branchStaff($this->dhaka, ['reports']);
        $this->actingAs($staff)->onPortal('manage')->withSession([BranchOperations::SessionView => 'all'])->get('/top-sellers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.top_sellers.national', fn ($rows) => $names($rows) === ['Dhaka Seller']));
        $this->actingAs($staff)->onPortal('manage')->get('/top-sellers?branch_id='.$this->rajshahi->id)->assertForbidden();

        $this->flushSession();
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get('/top-sellers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.top_sellers.national', fn ($rows) => count($rows) === 3));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function partnerAccountPages(): array
    {
        return ['dealers' => ['/accounts', 'dealer'], 'sub-dealers' => ['/sub-dealers', 'sub_dealer']];
    }

    #[DataProvider('partnerAccountPages')]
    public function test_partner_accounts_follow_the_partner_branch(string $path, string $role): void
    {
        $partners = collect($this->partnersPerBranch($role))->map(fn (User $partner): int => $partner->id);
        $all = $role === 'dealer' ? [...$partners->values()->all(), $this->partner->id] : $partners->values()->all();
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->dhaka->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'DHK-01')
            ->where('data.accounts.data', fn ($rows) => $this->ids($rows) === [$partners['dhaka']])
            ->where('data.account_status_counts', ['active' => 1]));
        $this->ownerOnAll()->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.accounts.data', fn ($rows) => $this->ids($rows) === $this->sortedIds($all)));
        $this->ownerOnAll()->get($path.'?branch_id='.$this->rajshahi->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.accounts.data', fn ($rows) => $this->ids($rows) === [$partners['rajshahi']]));

        $this->flushSession();
        $this->actingAs(User::factory()->staff('account_manager')->create())->onPortal('manage')->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.accounts.data', fn ($rows) => count($rows) === count($all)));
        // The accounts permission already covers every branch, so branch staff cannot open this page.
        $this->actingAs($this->branchStaff($this->dhaka))->onPortal('manage')->get($path)->assertForbidden();
    }

    public function test_employees_follow_the_branch_they_work_at(): void
    {
        $assignedToDhaka = User::factory()->staff('employee')->create();
        BranchStaffAssignment::query()->create(['branch_id' => $this->dhaka->id, 'user_id' => $assignedToDhaka->id, 'duty' => 'storekeeper', 'is_primary' => false]);
        $workingInRajshahi = User::factory()->staff('employee')->create(['active_branch_id' => $this->rajshahi->id]);
        $noBranch = User::factory()->staff('employee')->create();
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->dhaka->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/employees')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'DHK-01')
            ->where('data.employees.data', fn ($rows) => $this->ids($rows) === [$assignedToDhaka->id])
            ->where('data.employee_stats.total', 1));
        $this->ownerOnAll()->get('/employees?branch_id='.$this->rajshahi->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.employees.data', fn ($rows) => $this->ids($rows) === [$workingInRajshahi->id]));
        $this->ownerOnAll()->get('/employees')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.employees.data', fn ($rows) => $this->ids($rows) === $this->sortedIds([$assignedToDhaka->id, $workingInRajshahi->id, $noBranch->id])));

        $this->flushSession();
        $this->actingAs(User::factory()->staff('account_manager')->create())->onPortal('manage')->get('/employees')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.employee_stats.total', 4));
        $this->actingAs($this->branchStaff($this->dhaka))->onPortal('manage')->get('/employees')->assertForbidden();
    }

    public function test_the_head_office_lists_staff_who_have_no_branch_yet(): void
    {
        $noBranch = User::factory()->staff('employee')->create();
        $assignedToDhaka = User::factory()->staff('employee')->create();
        BranchStaffAssignment::query()->create(['branch_id' => $this->dhaka->id, 'user_id' => $assignedToDhaka->id, 'duty' => 'storekeeper', 'is_primary' => false]);
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->headOffice()->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/employees')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', $this->headOffice()->code)
            ->where('data.employees.data', fn ($rows) => $this->ids($rows) === [$noBranch->id])
            ->where('data.employee_stats.total', 1));
    }

    public function test_stock_movement_log_follows_the_branch_on_the_inventory_page(): void
    {
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->dhaka->id]);
        $movements = [];
        foreach (['dhaka' => $this->dhaka, 'rajshahi' => $this->rajshahi] as $key => $branch) {
            $movements[$key] = (int) DB::table('stock_movements')->insertGetId([
                'product_id' => $this->product->id,
                'branch_id' => $branch->id,
                'quantity' => 5,
                'reason' => 'opening',
                'reference' => (string) Str::uuid(),
                'kind' => 'product_in',
                'actor_id' => $owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($owner)->onPortal('sadmin')->get('/inventory')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'DHK-01')
            ->where('data.movements.data', fn ($rows) => $this->ids($rows) === [$movements['dhaka']]));
        $this->ownerOnAll()->get('/inventory')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.movements.data', fn ($rows) => $this->ids($rows) === $this->sortedIds(array_values($movements))));

        $this->flushSession();
        $storekeeper = User::factory()->staff('inventory')->create(['active_branch_id' => $this->rajshahi->id]);
        $this->actingAs($storekeeper)->onPortal('manage')->get('/inventory')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.movements.data', fn ($rows) => $this->ids($rows) === [$movements['rajshahi']]));
        $this->actingAs($this->branchStaff($this->dhaka))->onPortal('manage')->get('/inventory')->assertForbidden();
    }

    public function test_payment_claims_follow_the_order_branch(): void
    {
        $claims = collect($this->ordersPerBranch('dispatched', 'submitted'))->map(fn (string $number): int => $this->claimFor($number));
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->dhaka->id]);

        $this->ownerOnAll()->get('/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.claims.data', fn ($rows) => $this->ids($rows) === $this->sortedIds($claims->values()->all())));
        $this->flushSession();
        $this->actingAs($owner)->onPortal('sadmin')->get('/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'DHK-01')
            ->where('data.claims.data', fn ($rows) => $this->ids($rows) === [$claims['dhaka']])
            ->where('data.claim_status_counts', ['pending' => 1]));
        $this->ownerOnAll()->get('/payments?branch_id='.$this->rajshahi->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.claims.data', fn ($rows) => $this->ids($rows) === [$claims['rajshahi']]));
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get('/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.claims.data', fn ($rows) => count($rows) === 3));
        // Finance pages need the finance permission, which already covers every branch.
        $this->actingAs($this->branchStaff($this->dhaka))->onPortal('manage')->get('/payments')->assertForbidden();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function withdrawalPages(): array
    {
        return ['payouts hub' => ['/payouts-hub']];
    }

    #[DataProvider('withdrawalPages')]
    public function test_withdrawals_follow_the_partner_branch(string $path): void
    {
        $withdrawals = $this->withdrawalsPerBranch();
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->dhaka->id]);

        $this->ownerOnAll()->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.view', 'all')
            ->where('data.withdrawals.data', fn ($rows) => $this->ids($rows) === $this->sortedIds(array_values($withdrawals))));
        $this->flushSession();
        $this->actingAs($owner)->onPortal('sadmin')->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', 'DHK-01')
            ->where('data.withdrawals.data', fn ($rows) => $this->ids($rows) === [$withdrawals['dhaka']]));
        $this->ownerOnAll()->get($path.'?branch_id='.$this->rajshahi->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.withdrawals.data', fn ($rows) => $this->ids($rows) === [$withdrawals['rajshahi']]));
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.withdrawals.data', fn ($rows) => count($rows) === 3));
    }

    private function claimFor(string $orderNumber): int
    {
        return (int) DB::table('payment_claims')->insertGetId([
            'order_id' => Order::query()->where('number', $orderNumber)->value('id'),
            'channel' => 'bKash',
            'reference' => 'TX-'.Str::upper(Str::random(8)),
            'amount' => '100.00',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * One partner saved on the Dhaka branch, one matched to Rajshahi by place code,
     * and one with no branch at all.
     *
     * @return array{dhaka: User, rajshahi: User, none: User}
     */
    private function partnersPerBranch(string $role = 'dealer'): array
    {
        $rajshahiPlace = $this->placeRajshahiBranch();
        $factory = fn (): UserFactory => $role === 'dealer' ? User::factory()->dealer() : User::factory()->subDealer();

        return [
            'dhaka' => $factory()->create(['active_branch_id' => $this->dhaka->id, 'district' => 'Sylhet']),
            'rajshahi' => $factory()->inPlace($rajshahiPlace)->create(),
            'none' => $factory()->create(['district' => 'Sylhet']),
        ];
    }

    /** Put the Rajshahi branch on a district place code and return that place. */
    private function placeRajshahiBranch(): PlaceCode
    {
        $district = PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Rajshahi']))->create(['name' => 'Rajshahi']);
        $this->rajshahi->forceFill(['place_code_id' => $district->id])->save();

        return $district;
    }

    /**
     * One withdrawal request from each partner of {@see partnersPerBranch()}.
     *
     * @return array{dhaka: string, rajshahi: string, none: string}
     */
    private function withdrawalsPerBranch(): array
    {
        $partners = $this->partnersPerBranch();

        return array_map(function (User $partner): string {
            $id = (string) Str::uuid();
            DB::table('withdrawals')->insert([
                'id' => $id,
                'user_id' => $partner->id,
                'amount' => '200.00',
                'status' => 'requested',
                'channel' => 'Bank',
                'destination' => encrypt('TEST-ACCOUNT-1234'),
                'idempotency_key' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        }, $partners);
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<int|string>
     */
    private function ids(iterable $rows): array
    {
        return $this->sortedIds(collect($rows)->pluck('id')->all());
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int|string>
     */
    private function sortedIds(array $ids): array
    {
        sort($ids);

        return array_values($ids);
    }

    /**
     * @param  list<string>  $permissions
     */
    #[DataProvider('orderListPages')]
    public function test_the_head_office_also_lists_orders_with_no_branch_yet(string $path, string $status, string $unassignedStatus, array $permissions): void
    {
        $orders = $this->ordersPerBranch($status, $unassignedStatus);
        $sentFromHeadOffice = $this->order($this->headOffice()->id, $status)->number;
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->headOffice()->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', $this->headOffice()->code)
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === $this->sorted([$sentFromHeadOffice, $orders['none']])));

        $this->actingAs($this->branchStaff($this->headOffice(), $permissions))->onPortal('manage')->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.orders.data', fn ($rows) => $this->numbers($rows) === $this->sorted([$sentFromHeadOffice, $orders['none']])));
    }

    public function test_the_head_office_search_finds_an_order_with_no_branch_yet(): void
    {
        $orders = $this->ordersPerBranch('dispatched', 'submitted');
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->headOffice()->id]);

        $this->actingAs($owner)->onPortal('sadmin')->getJson('/commands/search?q='.$orders['none'])->assertOk()->assertJsonCount(1, 'data.orders');
        $this->actingAs($owner)->onPortal('sadmin')->getJson('/commands/search?q='.$orders['dhaka'])->assertOk()->assertJsonCount(0, 'data.orders');
    }

    public function test_the_head_office_work_queue_counts_new_orders_and_their_payments(): void
    {
        foreach ($this->ordersPerBranch('dispatched', 'submitted') as $number) {
            $this->claimFor($number);
        }
        $queueCount = fn (array $queue, string $key): int => (int) collect($queue)->firstWhere('key', $key)['count'];
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->headOffice()->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/overview')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.stats.order_count', 1)
            ->where('data.admin_home.work_queue', fn ($queue) => $queueCount($queue->all(), 'orders_to_review') === 1
                && $queueCount($queue->all(), 'payments_to_check') === 1));
    }

    public function test_the_head_office_lists_payments_for_orders_with_no_branch_yet(): void
    {
        $claims = collect($this->ordersPerBranch('dispatched', 'submitted'))->map(fn (string $number): int => $this->claimFor($number));
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->headOffice()->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.claims.data', fn ($rows) => $this->ids($rows) === [$claims['none']]));
    }

    #[DataProvider('partnerAccountPages')]
    public function test_the_head_office_also_lists_partners_with_no_branch(string $path, string $role): void
    {
        $partners = collect($this->partnersPerBranch($role))->map(fn (User $partner): int => $partner->id);
        $noBranch = $role === 'dealer' ? [$partners['none'], $this->partner->id] : [$partners['none']];
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->headOffice()->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branch_scope.code', $this->headOffice()->code)
            ->where('data.accounts.data', fn ($rows) => $this->ids($rows) === $this->sortedIds($noBranch)));
    }

    public function test_top_sellers_on_the_head_office_include_partners_with_no_branch(): void
    {
        $sellers = [
            User::factory()->dealer()->create(['name' => 'Dhaka Seller', 'active_branch_id' => $this->dhaka->id, 'district' => 'Sylhet']),
            User::factory()->dealer()->create(['name' => 'No Branch Seller', 'district' => 'Sylhet']),
        ];
        foreach ($sellers as $seller) {
            $this->partner = $seller;
            $this->order(null, 'dispatched');
        }
        $owner = User::factory()->staff()->create(['active_branch_id' => $this->headOffice()->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/top-sellers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.top_sellers.national', fn ($rows) => collect($rows)->pluck('name')->all() === ['No Branch Seller']));
    }

    private function headOffice(): CompanyBranch
    {
        return CompanyBranch::query()->where('is_head_office', true)->firstOrFail();
    }

    private function ownerOnAll(): self
    {
        return $this->actingAs(User::factory()->staff()->create())
            ->onPortal('sadmin')
            ->withSession([BranchOperations::SessionView => 'all']);
    }

    /**
     * A staff member who works at one branch and may not view every branch.
     *
     * @param  list<string>  $permissions
     */
    private function branchStaff(CompanyBranch $branch, array $permissions = ['orders']): User
    {
        $staff = User::factory()->staff('employee')->create([
            'active_branch_id' => $branch->id,
            'profile' => ['permissions' => $permissions],
        ]);
        BranchStaffAssignment::query()->create(['branch_id' => $branch->id, 'user_id' => $staff->id, 'duty' => 'storekeeper', 'is_primary' => true]);

        return $staff;
    }

    /**
     * One order at each branch and one not yet given a branch, each with one unit still to send.
     *
     * @return array{dhaka: string, rajshahi: string, none: string}
     */
    private function ordersPerBranch(string $status, string $unassignedStatus): array
    {
        return [
            'dhaka' => $this->order($this->dhaka->id, $status)->number,
            'rajshahi' => $this->order($this->rajshahi->id, $status)->number,
            'none' => $this->order(null, $unassignedStatus)->number,
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
        DB::table('order_lines')->insert([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'sku' => $this->product->sku,
            'quantity' => 2,
            'dispatched' => 1,
            'mrp' => '250.00',
            'unit_price' => '250.00',
            'total' => '500.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
