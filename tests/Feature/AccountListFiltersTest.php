<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class AccountListFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_dealers_filter_by_division_district_and_rank_on_the_server(): void
    {
        $owner = User::factory()->staff()->create();
        $gazipur = $this->dealer('Gazipur Dealer', 'Dhaka', 'Gazipur', 'mo');
        $narayanganj = $this->dealer('Narayanganj Dealer', 'Dhaka', 'Narayanganj');
        $jessore = $this->dealer('Jessore Dealer', 'Khulna', 'Jessore', 'dealer');

        $this->assertSame([$narayanganj->id, $gazipur->id], $this->ids($owner, '/accounts?division=Dhaka'));
        $this->assertSame([$gazipur->id], $this->ids($owner, '/accounts?division=Dhaka&district=Gazipur'));
        $this->assertSame([$gazipur->id], $this->ids($owner, '/accounts?rank=mo'));
        $this->assertSame([$jessore->id, $narayanganj->id], $this->ids($owner, '/accounts?rank=dealer'));

        $data = $this->page($owner, '/accounts?division=Dhaka&rank=mo');
        $this->assertSame(['active' => 1], $data['props']['data']['account_status_counts']);
        $this->assertSame('Dhaka', $data['props']['filters']['division']);
        $this->assertSame('mo', $data['props']['filters']['rank']);
        $this->assertSame([
            ['division' => 'Dhaka', 'districts' => ['Gazipur', 'Narayanganj']],
            ['division' => 'Khulna', 'districts' => ['Jessore']],
        ], $data['props']['data']['account_areas']);
    }

    public function test_quiet_partner_filter_uses_confirmed_orders_only(): void
    {
        $owner = User::factory()->staff()->create();
        $recent = $this->dealer('Recent Buyer', 'Dhaka', 'Gazipur');
        $older = $this->dealer('Older Buyer', 'Dhaka', 'Gazipur');
        $unconfirmed = $this->dealer('Only Submitted', 'Dhaka', 'Gazipur');
        $this->order($recent, 'delivered', '100.00', now()->subDays(10));
        $this->order($older, 'approved', '100.00', now()->subDays(45));
        $this->order($unconfirmed, 'submitted', '100.00', now()->subDay());

        $this->assertSame([$unconfirmed->id, $older->id], $this->ids($owner, '/accounts?no_order_days=30'));
        $this->assertSame([$unconfirmed->id], $this->ids($owner, '/accounts?no_order_days=60'));
        $this->actingAs($owner)->onPortal('sadmin')->get('/accounts?no_order_days=45')->assertSessionHasErrors('no_order_days');
    }

    public function test_list_shows_rank_last_confirmed_order_and_this_months_purchases(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = $this->dealer('Busy Dealer', 'Dhaka', 'Gazipur', 'am');
        $quiet = $this->dealer('Quiet Dealer', 'Dhaka', 'Gazipur');
        $monthStart = CarbonImmutable::now('Asia/Dhaka')->startOfMonth();
        $this->order($dealer, 'approved', '100.00', $monthStart->addHours(2));
        $last = $this->order($dealer, 'delivered', '50.25', $monthStart->addHours(5));
        $this->order($dealer, 'rejected', '999.00', $monthStart->addHours(6));
        $this->order($dealer, 'delivered', '400.00', $monthStart->subDay());

        $rows = collect($this->page($owner, '/accounts')['props']['data']['accounts']['data'])->keyBy('id');

        $this->assertSame('am', $rows[$dealer->id]['current_rank']);
        $this->assertSame('150.25', $rows[$dealer->id]['month_purchase']);
        $this->assertTrue(CarbonImmutable::parse($rows[$dealer->id]['last_order_at'])->equalTo($last->fresh()->created_at));
        $this->assertSame('dealer', $rows[$quiet->id]['current_rank']);
        $this->assertNull($rows[$quiet->id]['last_order_at']);
        $this->assertSame('0.00', $rows[$quiet->id]['month_purchase']);
    }

    public function test_excel_download_matches_the_filtered_list_and_never_holds_the_nid(): void
    {
        $manager = User::factory()->create(['role' => 'account_manager', 'status' => 'active']);
        $match = $this->dealer('Gazipur Dealer', 'Dhaka', 'Gazipur', 'mo');
        $match->forceFill(['nid' => '19901234567890123'])->save();
        $this->order($match, 'delivered', '1200.50', now());
        $this->dealer('Khulna Dealer', 'Khulna', 'Jessore', 'mo');
        $this->dealer('Unranked Dealer', 'Dhaka', 'Gazipur');

        $response = $this->actingAs($manager)->onPortal('manage')->get('/exports/accounts?section=accounts&division=Dhaka&rank=mo')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        [$list, $summary] = $this->sheets($response);

        $this->assertCount(3, $list, 'Header, one dealer and the totals row.');
        $this->assertSame('Gazipur Dealer', $list[1][0]);
        $this->assertContains(__('portal.net_rank_mo'), $list[1]);
        $this->assertSame(1200.5, $list[1][count($list[1]) - 1]);
        $this->assertSame(1200.5, $list[2][count($list[2]) - 1]);
        $everything = json_encode([$list, $summary]);
        $this->assertStringNotContainsString('19901234567890123', $everything);
        $this->assertStringNotContainsString('Khulna Dealer', $everything);
        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported', 'subject' => 'accounts']);
    }

    public function test_only_staff_who_manage_accounts_may_download_the_list(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $dealer = $this->dealer('Some Dealer', 'Dhaka', 'Gazipur');

        $this->actingAs($finance)->onPortal('manage')->get('/exports/accounts?section=accounts')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/exports/accounts?section=accounts')->assertForbidden();
    }

    public function test_view_only_staff_get_masked_phones_and_emails_while_account_editors_get_them_in_full(): void
    {
        $dealer = $this->dealer('Contact Dealer', 'Dhaka', 'Gazipur');
        $dealer->forceFill(['phone' => '01712345678', 'email' => 'rahim@example.test'])->save();
        $viewer = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['accounts'], 'action_permissions' => ['accounts' => ['view' => true]]]]);
        $editor = User::factory()->create(['role' => 'account_manager', 'status' => 'active']);

        [$list, $summary] = $this->sheets($this->actingAs($viewer)->onPortal('manage')->get('/exports/accounts?section=accounts')->assertOk());
        $everything = json_encode([$list, $summary], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('01712345678', $everything);
        $this->assertStringNotContainsString('rahim@example.test', $everything);
        $this->assertContains('••••5678', $list[1]);
        $this->assertContains('r•••@example.test', $list[1]);
        $this->assertStringContainsString(__('portal.acct_export_contacts_masked'), $everything);
        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported', 'actor_id' => $viewer->id]);
        $this->travel(61)->seconds();

        [$list] = $this->sheets($this->actingAs($editor)->onPortal('manage')->get('/exports/accounts?section=accounts')->assertOk());
        $this->assertContains('01712345678', $list[1]);
        $this->assertContains('rahim@example.test', $list[1]);
    }

    private function dealer(string $name, string $division, string $district, ?string $rank = null): User
    {
        $dealer = User::factory()->dealer()->create(['name' => $name, 'division' => $division, 'district' => $district, 'status' => 'active']);
        if ($rank !== null) {
            DB::table('dealer_ranks')->insert(['user_id' => $dealer->id, 'current_rank' => $rank, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $dealer;
    }

    private function order(User $buyer, string $status, string $total, \DateTimeInterface $createdAt): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => '0.00',
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => CarbonImmutable::instance($createdAt)->utc()]);

        return $order;
    }

    /** @return array<string, mixed> */
    private function page(User $staff, string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($staff)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json();
    }

    /** @return list<int> */
    private function ids(User $staff, string $path): array
    {
        return collect($this->page($staff, $path)['props']['data']['accounts']['data'])->pluck('id')->all();
    }

    /** @return array{0: list<list<mixed>>, 1: list<list<mixed>>} */
    private function sheets(TestResponse $response): array
    {
        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[] = $rows;
        }
        $reader->close();
        @unlink($response->baseResponse->getFile()->getPathname());

        return [$sheets[0], $sheets[1] ?? []];
    }
}
