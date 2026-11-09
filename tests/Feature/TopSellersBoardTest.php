<?php

namespace Tests\Feature;

use App\Domain\Orders\MixedOutcomeDecisions;
use App\Domain\Orders\TopSellersBoard;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use App\Support\DashboardManagement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TopSellersBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // 15 November 2026, midday in Dhaka.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'Asia/Dhaka'));
    }

    public function test_a_confirmed_order_counts_for_nothing_until_it_is_delivered(): void
    {
        $rahim = $this->dealer('Rahim', 'MNHC-101', 'Dhaka', 'Gazipur');
        foreach (['approved', 'partially_dispatched', 'dispatched'] as $status) {
            $this->order($rahim, $status, '5000.00');
        }

        $waiting = app(TopSellersBoard::class)->forPartner($rahim, null);

        $this->assertSame([], $waiting['national']);
        $this->assertSame([], $waiting['divisional']);
        $this->assertNull($waiting['me']);

        $this->order($rahim, 'delivered', '300.00');
        $delivered = app(TopSellersBoard::class)->forPartner($rahim, null);

        $this->assertSame('300.00', $delivered['national'][0]['amount']);
        $this->assertSame('300.00', $delivered['me']['amount']);
        $this->assertSame([], array_column(app(TopSellersBoard::class)->forStaff('2026-10', null)['national'], 'name'));
    }

    public function test_a_partly_delivered_order_counts_only_its_delivered_part(): void
    {
        $rahim = $this->dealer('Rahim', 'MNHC-101', 'Dhaka', 'Gazipur');
        $karim = $this->dealer('Karim', 'MNHC-102', 'Dhaka', 'Gazipur');
        $this->partlyDeliveredOrder($rahim, deliveredUnits: 3, returnedUnits: 7, unitPrice: '100.00');
        $this->order($karim, 'delivered', '200.00');

        $board = app(TopSellersBoard::class)->forPartner($rahim, null);

        $this->assertSame(
            [['Rahim', 1, '300.00'], ['Karim', 2, '200.00']],
            array_map(fn (array $row): array => [$row['name'], $row['rank'], $row['amount']], $board['national']),
        );
    }

    public function test_dealers_are_ranked_by_their_own_delivered_purchases_in_the_dhaka_month(): void
    {
        $rahim = $this->dealer('Rahim', 'MNHC-101', 'Dhaka', 'Gazipur');
        $karim = $this->dealer('Karim', 'MNHC-102', 'Dhaka', null);
        $suspended = $this->dealer('Suspended Seller', 'MNHC-103', 'Dhaka', 'Dhaka');
        $suspended->forceFill(['status' => 'suspended'])->save();
        $subDealer = User::factory()->subDealer()->inPlace($this->place('Dhaka', null))->create(['sponsor_id' => $rahim->id, 'directory_visible' => true]);

        foreach (['delivered' => '800.00', 'approved' => '2000.00', 'submitted' => '1000.00', 'rejected' => '900.00', 'cancelled' => '700.00'] as $status => $total) {
            $this->order($rahim, $status, $total, '2026-11-10 10:00:00');
        }
        $this->order($karim, 'delivered', '600.00', '2026-11-03 10:00:00');
        $this->order($karim, 'delivered', '50.00', '2026-11-04 10:00:00');
        // 11:59 pm on 31 October in Dhaka belongs to October; 12:30 am on 1 November to November.
        $this->order($karim, 'delivered', '1000.00', '2026-10-31 23:59:00');
        $this->order($karim, 'delivered', '50.00', '2026-11-01 00:30:00');
        $this->order($suspended, 'delivered', '5000.00', '2026-11-05 10:00:00');
        // A sub-dealer's purchases count for nobody: not for the sponsor, and sub-dealers are not ranked.
        $this->order($subDealer, 'delivered', '9000.00', '2026-11-05 10:00:00');

        $board = app(TopSellersBoard::class)->forPartner($rahim, null);

        $this->assertSame('2026-11', $board['month']);
        $this->assertSame(['2026-11', '2026-10', '2026-09'], $board['months']);
        $this->assertSame([
            ['rank' => 1, 'name' => 'Rahim', 'dealer_code' => 'MNHC-101', 'place' => 'Gazipur', 'amount' => '800.00', 'percent' => 100.0, 'is_me' => true],
            ['rank' => 2, 'name' => 'Karim', 'dealer_code' => 'MNHC-102', 'place' => 'Dhaka', 'amount' => '700.00', 'percent' => 87.5, 'is_me' => false],
        ], $board['national']);
        $this->assertSame(1, $board['me']['national_rank']);
        $this->assertTrue($board['me']['in_top']);

        $october = app(TopSellersBoard::class)->forPartner($rahim, '2026-10');
        $this->assertSame([['Karim', 1, '1000.00']], array_map(fn (array $row) => [$row['name'], $row['rank'], $row['amount']], $october['national']));
        $this->assertNull($october['me']);
    }

    public function test_only_this_month_and_the_last_two_closed_months_can_be_picked(): void
    {
        $rahim = $this->dealer('Rahim', 'MNHC-101', 'Dhaka', null);
        $this->order($rahim, 'delivered', '100.00', '2026-08-10 10:00:00');

        $board = app(TopSellersBoard::class)->forPartner($rahim, '2026-08');

        $this->assertSame('2026-11', $board['month']);
        $this->assertSame([], $board['national']);
    }

    public function test_division_board_ranks_inside_the_viewers_division_and_ties_share_a_place(): void
    {
        $dhakaOne = $this->dealer('Anis', 'MNHC-201', 'Dhaka', 'Narsingdi');
        $dhakaTwo = $this->dealer('Babul', 'MNHC-202', 'Dhaka', 'Tangail');
        $dhakaThree = $this->dealer('Chandan', 'MNHC-203', 'Dhaka', 'Faridpur');
        $rajshahi = $this->dealer('Dipu', 'MNHC-301', 'Rajshahi', 'Bogura');
        $this->order($dhakaOne, 'delivered', '400.00');
        $this->order($dhakaTwo, 'delivered', '400.00');
        $this->order($dhakaThree, 'delivered', '100.00');
        $this->order($rajshahi, 'delivered', '900.00');

        $board = app(TopSellersBoard::class)->forPartner($dhakaThree, null);

        $this->assertSame('Dhaka', $board['division']);
        $this->assertSame(
            [['Dipu', 1], ['Anis', 2], ['Babul', 2], ['Chandan', 4]],
            array_map(fn (array $row) => [$row['name'], $row['rank']], $board['national']),
        );
        $this->assertSame(
            [['Anis', 1, 100.0], ['Babul', 1, 100.0], ['Chandan', 3, 25.0]],
            array_map(fn (array $row) => [$row['name'], $row['rank'], $row['percent']], $board['divisional']),
        );
        $this->assertSame(4, $board['me']['national_rank']);
        $this->assertSame(3, $board['me']['division_rank']);
    }

    public function test_the_division_board_groups_by_division_place_code_not_by_name(): void
    {
        $viewer = $this->dealer('Anis', 'MNHC-801', 'Dhaka', 'Gazipur');
        $sameDivision = $this->dealer('Babul', 'MNHC-802', 'Dhaka', 'Tangail');
        $otherDhaka = User::factory()->dealer()->inPlace(PlaceCode::factory()->division()->create(['name' => 'Dhaka']))
            ->create(['name' => 'Other Dhaka', 'dealer_code' => 'MNHC-803']);
        $namesOnly = User::factory()->dealer()->create(['name' => 'Names Only', 'dealer_code' => 'MNHC-804', 'division' => 'Dhaka', 'district' => 'Gazipur']);
        foreach ([$viewer, $sameDivision, $otherDhaka, $namesOnly] as $dealer) {
            $this->order($dealer, 'delivered', '100.00');
        }

        $board = app(TopSellersBoard::class)->forPartner($viewer, null);

        $this->assertSame('Dhaka', $board['division']);
        $this->assertSame($this->place('Dhaka', null)->id, $board['division_id']);
        $this->assertSame(['Anis', 'Babul'], array_column($board['divisional'], 'name'));
        $this->assertCount(4, $board['national']);
        $namesOnlyBoard = app(TopSellersBoard::class)->forPartner($namesOnly, null);
        $this->assertNull($namesOnlyBoard['division']);
        $this->assertSame([], $namesOnlyBoard['divisional']);
    }

    public function test_viewer_outside_the_top_ten_sees_their_own_place(): void
    {
        foreach (range(1, 22) as $index) {
            $dealer = $this->dealer('Dealer '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'MNHC-4'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'Khulna', 'Jashore');
            $this->order($dealer, 'delivered', (string) (5000 - $index * 10).'.00');
        }
        $viewer = $this->dealer('Rahim', 'MNHC-500', 'Khulna', 'Jashore');
        $this->order($viewer, 'delivered', '100.00');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $board = app(TopSellersBoard::class)->forPartner($viewer, null);
        $rankingQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'RANK() OVER'));
        DB::disableQueryLog();

        $this->assertCount(1, $rankingQueries, 'The whole board must come from one grouped query.');
        $this->assertCount(10, $board['national']);
        $this->assertCount(10, $board['divisional']);
        $this->assertFalse(collect($board['national'])->contains('is_me', true));
        $this->assertSame(23, $board['me']['national_rank']);
        $this->assertSame(23, $board['me']['division_rank']);
        $this->assertFalse($board['me']['in_top']);
        $this->assertSame('100.00', $board['me']['amount']);
    }

    public function test_dealers_left_out_of_the_public_directory_still_appear_on_the_board(): void
    {
        $unlisted = $this->dealer('Unlisted Leader', 'MNHC-601', 'Sylhet', 'Sylhet', visible: false);
        $listed = $this->dealer('Listed Second', 'MNHC-602', 'Sylhet', 'Moulvibazar');
        $this->order($unlisted, 'delivered', '1000.00');
        $this->order($listed, 'delivered', '500.00');

        $board = app(TopSellersBoard::class)->forPartner($listed, null);
        $this->assertSame([['Unlisted Leader', 1, 100.0], ['Listed Second', 2, 50.0]], array_map(fn (array $row) => [$row['name'], $row['rank'], $row['percent']], $board['national']));

        $ownBoard = app(TopSellersBoard::class)->forPartner($unlisted, null);
        $this->assertSame([['Unlisted Leader', 1, true], ['Listed Second', 2, false]], array_map(fn (array $row) => [$row['name'], $row['rank'], $row['is_me']], $ownBoard['national']));
        $this->assertSame(1, $ownBoard['me']['national_rank']);

        $sylhet = $this->place('Sylhet', null);
        $staffBoard = app(TopSellersBoard::class)->forStaff(null, (string) $sylhet->id);
        $this->assertSame(['Unlisted Leader', 'Listed Second'], array_column($staffBoard['national'], 'name'));
        $this->assertSame([['id' => $sylhet->id, 'name' => 'Sylhet']], $staffBoard['divisions']);
        $this->assertSame('Sylhet', $staffBoard['division']);
    }

    public function test_sub_dealers_see_the_dealer_board_for_their_division(): void
    {
        $dhaka = $this->dealer('Rahim', 'MNHC-101', 'Dhaka', 'Gazipur');
        $rangpur = $this->dealer('Selim', 'MNHC-701', 'Rangpur', 'Dinajpur');
        $this->order($dhaka, 'delivered', '300.00');
        $this->order($rangpur, 'delivered', '800.00');
        $subDealer = User::factory()->subDealer()->inPlace($this->place('Dhaka', null))->create(['sponsor_id' => $dhaka->id]);

        $this->actingAs($subDealer)->onPortal('subdealer')->get('/top-sellers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'top-sellers')
                ->where('data.top_sellers.division', 'Dhaka')
                ->where('data.top_sellers.can_rank', false)
                ->where('data.top_sellers.me', null)
                ->has('data.top_sellers.national', 2)
                ->has('data.top_sellers.divisional', 1)
                ->where('data.top_sellers.divisional.0.name', 'Rahim')
                ->where('data.top_sellers.divisional.0.place', 'Gazipur'));
    }

    public function test_owner_switches_hide_the_amounts_or_the_whole_board(): void
    {
        $rahim = $this->dealer('Rahim', 'MNHC-101', 'Dhaka', 'Gazipur');
        $this->order($rahim, 'delivered', '800.00');
        $owner = User::factory()->staff()->create();

        $this->actingAs($rahim)->onPortal('dealer')->get('/top-sellers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.top_sellers.show_amounts', true)
                ->where('data.top_sellers.national.0.amount', '800.00')
                ->where('dashboardControls.top_sellers_enabled', true));

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/dashboards', ['settings' => ['top_sellers_show_amounts' => 'false']])
            ->assertSessionHas('success');
        $this->assertFalse(DashboardManagement::get()['top_sellers_show_amounts']);

        $this->actingAs($rahim)->onPortal('dealer')->get('/top-sellers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.top_sellers.show_amounts', false)
                ->where('data.top_sellers.national.0.amount', null)
                ->where('data.top_sellers.national.0.percent', 100)
                ->where('data.top_sellers.me.amount', null));
        // The office view always shows the amounts.
        $this->actingAs($owner)->onPortal('sadmin')->get('/top-sellers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.top_sellers.national.0.amount', '800.00'));

        DashboardManagement::save(['top_sellers_enabled' => false]);
        $this->actingAs($rahim)->onPortal('dealer')->get('/top-sellers')->assertForbidden();
        $subDealer = User::factory()->subDealer()->create(['sponsor_id' => $rahim->id]);
        $this->actingAs($subDealer)->onPortal('subdealer')->get('/top-sellers')->assertForbidden();
        $this->actingAs($owner)->onPortal('sadmin')->get('/top-sellers')->assertOk();
    }

    public function test_switches_default_to_showing_the_board_and_the_amounts(): void
    {
        $this->assertTrue(DashboardManagement::defaults()['top_sellers_enabled']);
        $this->assertTrue(DashboardManagement::defaults()['top_sellers_show_amounts']);
    }

    public function test_the_board_never_carries_earnings_or_commission(): void
    {
        $rahim = $this->dealer('Rahim', 'MNHC-101', 'Dhaka', 'Gazipur');
        $this->order($rahim, 'delivered', '800.00');

        $response = $this->actingAs($rahim)->onPortal('dealer')->get('/top-sellers')->assertOk();
        $board = $response->original->getData()['page']['props']['data']['top_sellers'];
        $staffBoard = app(TopSellersBoard::class)->forStaff(null, (string) $this->place('Dhaka', null)->id);

        foreach ([$board, $staffBoard] as $payload) {
            $encoded = mb_strtolower((string) json_encode($payload));
            foreach (['commission', 'earning', 'income', 'salary', 'royalty', 'wallet', 'bonus', 'reward', 'profit'] as $word) {
                $this->assertStringNotContainsString($word, $encoded, "The top-sellers board must not mention {$word}.");
            }
        }
    }

    public function test_office_staff_see_the_board_under_reports(): void
    {
        $rahim = $this->dealer('Rahim', 'MNHC-101', 'Dhaka', 'Gazipur');
        $this->order($rahim, 'delivered', '800.00');
        $reports = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['reports']]]);
        $inventory = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['inventory']]]);

        $this->actingAs($reports)->onPortal('manage')->get('/top-sellers?month=2026-10&division='.$this->place('Dhaka', null)->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.top_sellers.month', '2026-10')
                ->where('data.top_sellers.division', 'Dhaka')
                ->has('data.top_sellers.national', 0));
        $this->actingAs($inventory)->onPortal('manage')->get('/top-sellers')->assertForbidden();
    }

    /**
     * A dealer keyed to a place code: the district under the named division, or the
     * division itself when no district is given, or no place at all.
     */
    private function dealer(string $name, string $code, ?string $division, ?string $district, bool $visible = true): User
    {
        $place = $this->place($division, $district);
        $factory = User::factory()->dealer();

        return ($place !== null ? $factory->inPlace($place) : $factory)->create([
            'name' => $name,
            'dealer_code' => $code,
            'directory_visible' => $visible,
        ]);
    }

    /** @var array<string, PlaceCode> */
    private array $places = [];

    /** One place code per division (and district) name used by these tests. */
    private function place(?string $division, ?string $district): ?PlaceCode
    {
        if ($division === null) {
            return null;
        }
        $divisionPlace = $this->places['division:'.$division] ??= PlaceCode::factory()->division()->create(['name' => $division]);
        if ($district === null) {
            return $divisionPlace;
        }

        return $this->places['district:'.$division.'|'.$district] ??= PlaceCode::factory()->district($divisionPlace)->create(['name' => $district]);
    }

    /** A partly delivered order of one line: some parcels arrived, the others came back. */
    private function partlyDeliveredOrder(User $buyer, int $deliveredUnits, int $returnedUnits, string $unitPrice): void
    {
        $units = $deliveredUnits + $returnedUnits;
        $total = number_format($units * (float) $unitPrice, 2, '.', '');
        $orderId = $this->order($buyer, MixedOutcomeDecisions::PartlyDelivered, $total);
        $product = Product::factory()->create();
        $lineId = DB::table('order_lines')->insertGetId([
            'order_id' => $orderId, 'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => $units, 'dispatched' => $units, 'mrp' => $unitPrice, 'unit_price' => $unitPrice, 'total' => $total,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['delivered' => $deliveredUnits, 'returned' => $returnedUnits] as $status => $quantity) {
            DB::table('shipments')->insert([
                'order_id' => $orderId, 'courier' => 'Test courier', 'tracking' => Str::random(10), 'status' => $status,
                'lines' => json_encode([['line_id' => $lineId, 'name' => 'Item', 'quantity' => $quantity, 'lots' => []]], JSON_THROW_ON_ERROR),
                'actor_id' => $buyer->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** One order placed at a Dhaka wall-clock time (stored in UTC like the app does). Returns its id. */
    private function order(User $buyer, string $status, string $total, string $dhakaTime = '2026-11-10 10:00:00'): string
    {
        $createdAt = CarbonImmutable::parse($dhakaTime, 'Asia/Dhaka')->utc();
        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'number' => 'MN-TS-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => $total,
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $orderId;
    }
}
