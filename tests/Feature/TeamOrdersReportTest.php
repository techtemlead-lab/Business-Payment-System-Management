<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class TeamOrdersReportTest extends TestCase
{
    use RefreshDatabase;

    private const Range = 'from=2026-03-01&to=2026-03-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_dealer_sees_every_team_order_in_the_dates_with_paging_and_whole_range_totals(): void
    {
        [$leader, $direct, $deep] = $this->team();
        foreach (range(1, 30) as $index) {
            $this->order($direct, '100.00', '60.00', 'delivered', '2026-03-05 06:00:00', 'ORD-D'.$index);
        }
        $this->order($deep, '250.00', '250.00', 'approved', '2026-03-10 06:00:00', 'ORD-DEEP');
        $this->order($deep, '999.00', '0.00', 'submitted', '2026-03-10 06:00:00', 'ORD-NOT-CONFIRMED');
        $this->order($deep, '999.00', '999.00', 'delivered', '2026-04-02 06:00:00', 'ORD-AFTER');
        $this->order($leader, '777.00', '777.00', 'delivered', '2026-03-10 06:00:00', 'ORD-OWN');

        $first = $this->page($leader, '/team-orders?'.self::Range);
        $second = $this->page($leader, '/team-orders?'.self::Range.'&page=2');

        $this->assertSame(31, $first['orders']['total']);
        $this->assertCount(25, $first['orders']['data']);
        $this->assertCount(6, $second['orders']['data']);
        $this->assertSame(['orders' => 31, 'buyers' => 2, 'ordered' => '3000.00', 'paid' => '1800.00', 'due' => '1200.00'], $first['totals']);
        $this->assertSame('ORD-DEEP', $first['orders']['data'][0]['number'], 'Newest first.');
        $this->assertEqualsCanonicalizing([$direct->id, $deep->id], array_column($first['members'], 'id'));
        $this->assertFalse($first['staffView']);
    }

    public function test_member_and_status_filters_narrow_the_list_and_the_totals(): void
    {
        [$leader, $direct, $deep] = $this->team();
        $this->order($direct, '100.00', '100.00', 'delivered', '2026-03-05 06:00:00', 'ORD-A');
        $this->order($deep, '200.00', '50.00', 'approved', '2026-03-06 06:00:00', 'ORD-B');
        $this->order($deep, '300.00', '300.00', 'delivered', '2026-03-07 06:00:00', 'ORD-C');

        $byMember = $this->page($leader, '/team-orders?'.self::Range.'&member='.$deep->id);
        $this->assertSame(['ORD-C', 'ORD-B'], array_column($byMember['orders']['data'], 'number'));
        $this->assertSame('300.00', $byMember['totals']['ordered'], 'Only the delivered order counts in money.');

        $byStatus = $this->page($leader, '/team-orders?'.self::Range.'&member='.$deep->id.'&status=approved');
        $this->assertSame(['ORD-B'], array_column($byStatus['orders']['data'], 'number'));
        $this->assertSame('150.00', $byStatus['totals']['due']);

        $this->actingAs($leader)->onPortal('dealer')->get('/team-orders?status=submitted')->assertSessionHasErrors('status');
    }

    public function test_a_member_outside_the_downline_is_refused(): void
    {
        [$leader, $direct] = $this->team();
        $stranger = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($stranger->id, null);
        $this->order($stranger, '100.00', '100.00', 'delivered', '2026-03-05 06:00:00', 'ORD-STRANGER');

        $this->actingAs($leader)->onPortal('dealer')->get('/team-orders?'.self::Range.'&member='.$stranger->id)->assertNotFound();
        $this->actingAs($direct)->onPortal('dealer')->get('/team-orders?'.self::Range.'&member='.$leader->id)->assertNotFound('An upline is not part of the downline.');
        $this->assertSame(0, $this->page($direct, '/team-orders?'.self::Range)['orders']['total']);
    }

    public function test_staff_with_accounts_or_finance_can_open_any_partners_team_orders(): void
    {
        [$leader, $direct] = $this->team();
        $this->order($direct, '100.00', '40.00', 'delivered', '2026-03-05 06:00:00', 'ORD-A');

        foreach ([['account_manager', 'manage'], ['finance', 'manage']] as [$role, $portal]) {
            $staff = User::factory()->create(['role' => $role, 'status' => 'active']);
            $page = $this->page($staff, '/accounts/'.$leader->id.'/team-orders?'.self::Range, $portal);
            $this->assertSame(1, $page['orders']['total']);
            $this->assertTrue($page['staffView']);
            $this->assertSame('/accounts/'.$leader->id.'/team-orders', $page['reportBase']);
        }
        $owner = User::factory()->staff()->create();
        $this->assertSame(1, $this->page($owner, '/accounts/'.$leader->id.'/team-orders?'.self::Range, 'sadmin')['orders']['total']);

        $storekeeper = User::factory()->create(['role' => 'inventory', 'status' => 'active']);
        $this->actingAs($storekeeper)->onPortal('manage')->get('/accounts/'.$leader->id.'/team-orders')->assertForbidden();
        $this->actingAs($storekeeper)->onPortal('manage')->get('/accounts/'.$leader->id.'/team-orders/xlsx')->assertForbidden();
        $this->actingAs($owner)->onPortal('sadmin')->get('/accounts/'.$storekeeper->id.'/team-orders')->assertNotFound();
        $this->actingAs($leader)->onPortal('dealer')->get('/accounts/'.$direct->id.'/team-orders')->assertNotFound();
    }

    public function test_excel_download_holds_every_matching_order_with_totals(): void
    {
        [$leader, $direct, $deep] = $this->team();
        foreach (range(1, 30) as $index) {
            $this->order($direct, '10.00', '10.00', 'delivered', '2026-03-05 06:00:00', 'ORD-X'.$index);
        }
        $this->order($deep, '50.00', '0.00', 'approved', '2026-03-06 06:00:00', 'ORD-Y');

        $response = $this->actingAs($leader)->onPortal('dealer')->get('/team-orders/xlsx?'.self::Range)
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $rows = $this->rows($response);

        $this->assertCount(33, $rows, 'Header, 31 orders and the totals row.');
        $this->assertSame('ORD-X1', $rows[1][1]);
        $this->assertSame(350.0, (float) $rows[32][5]);
        $this->assertSame(50.0, (float) $rows[32][7]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'report.exported')->where('subject', 'team-orders')->count());

        $staff = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $this->rows($this->actingAs($staff)->onPortal('manage')->get('/accounts/'.$leader->id.'/team-orders/xlsx?'.self::Range.'&member='.$deep->id)->assertOk());
    }

    public function test_team_orders_query_count_does_not_grow_with_the_number_of_orders(): void
    {
        [$leader, $direct] = $this->team();
        $this->order($direct, '10.00', '10.00', 'delivered', '2026-03-05 06:00:00', 'ORD-1');
        $this->page($leader, '/team-orders?'.self::Range);
        $count = $this->countQueries(fn () => $this->page($leader, '/team-orders?'.self::Range));

        foreach (range(2, 20) as $index) {
            $this->order($direct, '10.00', '10.00', 'delivered', '2026-03-05 06:00:00', 'ORD-'.$index);
        }

        $this->assertSame($count, $this->countQueries(fn () => $this->page($leader, '/team-orders?'.self::Range)));
    }

    /**
     * A dealer with one direct recruit, who has a recruit of their own.
     *
     * @return array{0: User, 1: User, 2: User}
     */
    private function team(): array
    {
        $leader = User::factory()->dealer()->create(['status' => 'active']);
        $direct = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $leader->id]);
        $deep = User::factory()->subDealer()->create(['status' => 'active', 'sponsor_id' => $direct->id]);
        ReferralTreePath::attachUser($leader->id, null);
        ReferralTreePath::attachUser($direct->id, $leader->id);
        ReferralTreePath::attachUser($deep->id, $direct->id);

        return [$leader, $direct, $deep];
    }

    private function order(User $buyer, string $total, string $paid, string $status, string $createdAt, string $number): void
    {
        $order = Order::create(['number' => $number, 'user_id' => $buyer->id, 'status' => $status, 'total' => $total, 'paid' => $paid, 'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random())]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $createdAt]);
    }

    /** @return array<string, mixed> */
    private function page(User $viewer, string $path, string $portal = 'dealer'): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($viewer)->onPortal($portal)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->assertJsonPath('component', 'TeamOrders')->json('props');
    }

    private function countQueries(callable $callback): int
    {
        auth()->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $callback();
        } finally {
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $count;
    }

    /** @return list<list<mixed>> */
    private function rows(TestResponse $response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        @unlink($path);

        return $rows;
    }
}
