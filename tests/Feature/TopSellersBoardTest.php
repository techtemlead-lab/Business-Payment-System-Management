<?php

namespace Tests\Feature;

use App\Domain\Orders\TopSellersBoard;
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

    public function test_dealers_are_ranked_by_their_own_confirmed_purchases_in_the_dhaka_month(): void
    {
        $rahim = $this->dealer('Rahim', 'MNHC-101', 'Dhaka', 'Gazipur');
        $karim = $this->dealer('Karim', 'MNHC-102', 'Dhaka', null);
        $suspended = $this->dealer('Suspended Seller', 'MNHC-103', 'Dhaka', 'Dhaka');
        $suspended->forceFill(['status' => 'suspended'])->save();
        $subDealer = User::factory()->subDealer()->create(['sponsor_id' => $rahim->id, 'division' => 'Dhaka', 'directory_visible' => true]);

        foreach (['approved' => '500.00', 'delivered' => '300.00', 'submitted' => '1000.00', 'rejected' => '900.00', 'cancelled' => '700.00'] as $status => $total) {
            $this->order($rahim, $status, $total, '2026-11-10 10:00:00');
        }
        $this->order($karim, 'dispatched', '600.00', '2026-11-03 10:00:00');
        $this->order($karim, 'partially_dispatched', '50.00', '2026-11-04 10:00:00');
        // 11:59 pm on 31 October in Dhaka belongs to October; 12:30 am on 1 November to November.
        $this->order($karim, 'approved', '1000.00', '2026-10-31 23:59:00');
        $this->order($karim, 'approved', '50.00', '2026-11-01 00:30:00');
        $this->order($suspended, 'approved', '5000.00', '2026-11-05 10:00:00');
        // A sub-dealer's purchases count for nobody: not for the sponsor, and sub-dealers are not ranked.
        $this->order($subDealer, 'approved', '9000.00', '2026-11-05 10:00:00');

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
        $this->order($rahim, 'approved', '100.00', '2026-08-10 10:00:00');

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
        $this->order($dhakaOne, 'approved', '400.00');
        $this->order($dhakaTwo, 'approved', '400.00');
        $this->order($dhakaThree, 'approved', '100.00');
        $this->order($rajshahi, 'approved', '900.00');

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

    public function test_viewer_outside_the_top_ten_sees_their_own_place(): void
    {
        foreach (range(1, 22) as $index) {
            $dealer = $this->dealer('Dealer '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'MNHC-4'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'Khulna', 'Jashore');
            $this->order($dealer, 'approved', (string) (5000 - $index * 10).'.00');
        }
        $viewer = $this->dealer('Rahim', 'MNHC-500', 'Khulna', 'Jashore');
        $this->order($viewer, 'approved', '100.00');

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

    public function test_dealers_hidden_from_the_public_list_keep_their_place_but_are_not_shown(): void
    {
        $hidden = $this->dealer('Hidden Leader', 'MNHC-601', 'Sylhet', 'Sylhet', visible: false);
        $shown = $this->dealer('Shown Second', 'MNHC-602', 'Sylhet', 'Moulvibazar');
        $this->order($hidden, 'approved', '1000.00');
        $this->order($shown, 'approved', '500.00');

        $publicBoard = app(TopSellersBoard::class)->forPartner($shown, null);
        $this->assertSame([['Shown Second', 2, 50.0]], array_map(fn (array $row) => [$row['name'], $row['rank'], $row['percent']], $publicBoard['national']));
        $this->assertSame([['Shown Second', 2, 50.0]], array_map(fn (array $row) => [$row['name'], $row['rank'], $row['percent']], $publicBoard['divisional']));

        $ownBoard = app(TopSellersBoard::class)->forPartner($hidden, null);
        $this->assertSame([['Hidden Leader', 1, true], ['Shown Second', 2, false]], array_map(fn (array $row) => [$row['name'], $row['rank'], $row['is_me']], $ownBoard['national']));
        $this->assertSame(1, $ownBoard['me']['national_rank']);

        $staffBoard = app(TopSellersBoard::class)->forStaff(null, 'Sylhet');
        $this->assertSame([['Hidden Leader', true], ['Shown Second', false]], array_map(fn (array $row) => [$row['name'], $row['hidden']], $staffBoard['national']));
        $this->assertSame(['Sylhet'], $staffBoard['divisions']);
    }

    public function test_sub_dealers_see_the_dealer_board_for_their_division(): void
    {
        $dhaka = $this->dealer('Rahim', 'MNHC-101', 'Dhaka', 'Gazipur');
        $rangpur = $this->dealer('Selim', 'MNHC-701', 'Rangpur', 'Dinajpur');
        $this->order($dhaka, 'approved', '300.00');
        $this->order($rangpur, 'approved', '800.00');
        $subDealer = User::factory()->subDealer()->create(['sponsor_id' => $dhaka->id, 'division' => 'Dhaka']);

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
        $this->order($rahim, 'approved', '800.00');
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
        $this->order($rahim, 'approved', '800.00');

        $response = $this->actingAs($rahim)->onPortal('dealer')->get('/top-sellers')->assertOk();
        $board = $response->original->getData()['page']['props']['data']['top_sellers'];
        $staffBoard = app(TopSellersBoard::class)->forStaff(null, 'Dhaka');

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
        $this->order($rahim, 'approved', '800.00');
        $reports = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['reports']]]);
        $inventory = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['inventory']]]);

        $this->actingAs($reports)->onPortal('manage')->get('/top-sellers?month=2026-10&division=Dhaka')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.top_sellers.month', '2026-10')
                ->where('data.top_sellers.division', 'Dhaka')
                ->has('data.top_sellers.national', 0));
        $this->actingAs($inventory)->onPortal('manage')->get('/top-sellers')->assertForbidden();
    }

    private function dealer(string $name, string $code, ?string $division, ?string $district, bool $visible = true): User
    {
        return User::factory()->dealer()->create([
            'name' => $name,
            'dealer_code' => $code,
            'division' => $division,
            'district' => $district,
            'directory_visible' => $visible,
        ]);
    }

    /** One order placed at a Dhaka wall-clock time (stored in UTC like the app does). */
    private function order(User $buyer, string $status, string $total, string $dhakaTime = '2026-11-10 10:00:00'): void
    {
        $createdAt = CarbonImmutable::parse($dhakaTime, 'Asia/Dhaka')->utc();
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
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
    }
}
