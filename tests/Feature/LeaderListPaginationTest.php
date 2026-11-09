<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Order;
use App\Models\User;
use App\Support\ListPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner pages that used to send every leader at once (ranks, the car and bike fund, month closing,
 * area dealers) now send one page of 25 rows, searched on the server, while the counts and
 * totals at the top still cover everyone.
 */
class LeaderListPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const Leaders = 30;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff()->create();
    }

    public function test_ranks_page_sends_one_page_with_counts_over_every_leader(): void
    {
        $this->leaders(self::Leaders);

        $data = $this->sectionData('/ranks');
        $this->assertCount(ListPage::PerPage, $data['ranks']['data']);
        $this->assertSame(self::Leaders, $data['ranks']['total']);
        $this->assertSame(15, $data['stats']['mo_count']);
        $this->assertSame(15, $data['stats']['am_count']);

        $this->assertCount(self::Leaders - ListPage::PerPage, $this->sectionData('/ranks?page=2')['ranks']['data']);
        $found = $this->sectionData('/ranks?q=leader+7');
        $this->assertSame(['Leader 7'], array_column($found['ranks']['data'], 'name'));
        $this->assertSame(15, $found['stats']['mo_count'], 'Counts do not shrink with the search.');
        $this->assertSame(15, $this->sectionData('/ranks?rank=am')['ranks']['total']);
    }

    public function test_car_and_bike_fund_page_sends_one_page_with_the_total_over_every_row(): void
    {
        foreach ($this->leaders(self::Leaders) as $index => $leader) {
            $this->carBikeFundRow($leader, sprintf('RW-%03d', $index + 1));
        }

        $table = $this->pageProp('/treasury/funds/car_bike', 'table');
        $this->assertCount(ListPage::PerPage, $table['rows']['data']);
        $this->assertSame(self::Leaders, $table['totals']['count']);
        $this->assertSame('300.00', $table['totals']['sums']['amount'], 'The total covers every row, not only the page on screen.');

        $this->assertCount(self::Leaders - ListPage::PerPage, $this->pageProp('/treasury/funds/car_bike?page=2', 'table')['rows']['data']);
        $found = $this->pageProp('/treasury/funds/car_bike?q=RW-012', 'table');
        $this->assertSame(['RW-012'], array_column($found['rows']['data'], 'order_number'));
    }

    public function test_month_closing_lists_are_paged_and_the_counts_cover_every_leader(): void
    {
        $this->leaders(self::Leaders);
        $month = now('Asia/Dhaka')->subMonthNoOverflow()->format('Y-m');

        $state = $this->sectionData('/settlements?period_month='.$month)['settlement_state'];
        $this->assertCount(ListPage::PerPage, $state['qualified_rank_leaders']['data']);
        $this->assertSame(self::Leaders, $state['qualified_rank_leaders']['total']);

        $second = $this->sectionData('/settlements?period_month='.$month.'&leaders_page=2')['settlement_state'];
        $this->assertCount(self::Leaders - ListPage::PerPage, $second['qualified_rank_leaders']['data']);
        $this->assertSame($state['qualified_rank_leaders_count'], $second['qualified_rank_leaders_count']);

        $searched = $this->sectionData('/settlements?period_month='.$month.'&q=leader+21')['settlement_state'];
        $this->assertSame(['Leader 21'], array_column($searched['qualified_rank_leaders']['data'], 'name'));
        $this->assertSame($state['qualified_rank_leaders_count'], $searched['qualified_rank_leaders_count'], 'The count is not cut by the search.');
    }

    public function test_area_dealers_page_sends_one_page_with_totals_over_every_dealer(): void
    {
        $this->leaders(self::Leaders);

        $data = $this->sectionData('/area-dealers');
        $this->assertCount(ListPage::PerPage, $data['assignments']['data']);
        $this->assertSame(self::Leaders, $data['assignments']['total']);
        $this->assertSame(self::Leaders, $data['area_summary']['total']);
        $this->assertSame('1200000.00', $data['area_summary']['total_quota'], 'Thirty district seats at 40,000 each.');
        $dhaka = collect($data['area_summary']['divisions'])->firstWhere('division', 'Dhaka');
        $this->assertSame(15, $dhaka['count']);

        $filtered = $this->sectionData('/area-dealers?area_division=Dhaka');
        $this->assertSame(15, $filtered['assignments']['total']);
        $this->assertSame(self::Leaders, $filtered['area_summary']['total'], 'The cards keep counting everyone.');
        $this->assertSame(1, $this->sectionData('/area-dealers?q=leader+4')['assignments']['total']);
    }

    /** @return Collection<int, User> */
    private function leaders(int $count): Collection
    {
        $leaders = collect();
        foreach (range(1, $count) as $number) {
            $leader = User::factory()->dealer()->create([
                'name' => 'Leader '.$number,
                'status' => 'active',
                'division' => $number % 2 === 0 ? 'Dhaka' : 'Rangpur',
                'district' => $number % 2 === 0 ? 'Gazipur' : 'Rangpur',
            ]);
            DB::table('dealer_ranks')->insert([
                'user_id' => $leader->id,
                'current_rank' => $number % 2 === 0 ? 'mo' : 'am',
                'lifetime_purchase_total' => 1000 * $number,
                'achieved_at' => now()->subYear(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $leaders->push($leader);
        }

        return $leaders;
    }

    private function carBikeFundRow(User $leader, string $orderNumber): void
    {
        $order = Order::create(['user_id' => $leader->id, 'number' => $orderNumber, 'total' => '1000.00', 'paid' => '1000.00', 'status' => 'delivered',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $orderNumber)]);
        DB::table('commission_allocations')->insert(['order_id' => $order->id, 'category' => 'car_bike_incentive_fund', 'rate_percentage' => '1.00',
            'cash_basis' => '1000.00', 'amount' => '10.00', 'original_amount' => '10.00', 'status' => 'reserved', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function sectionData(string $path): array
    {
        return $this->pageProp($path, 'data');
    }

    /** @return array<string, mixed> */
    private function pageProp(string $path, string $prop): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json('props.'.$prop);
    }
}
