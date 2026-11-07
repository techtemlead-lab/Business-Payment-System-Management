<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\ListPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner pages that used to send every leader at once (ranks, bike/car rewards, month closing,
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

    public function test_rewards_page_sends_only_the_tab_on_screen_one_page_at_a_time(): void
    {
        $leaders = $this->leaders(self::Leaders);
        foreach ($leaders as $index => $leader) {
            $this->milestone($leader, 'bike', 1000 * ($index + 1));
        }
        foreach ($leaders->take(3) as $leader) {
            $this->milestone($leader, 'car', 500);
        }

        $bike = $this->sectionData('/rewards');
        $this->assertSame('bike', $bike['milestone_type']);
        $this->assertCount(ListPage::PerPage, $bike['milestones']['data']);
        $this->assertSame(self::Leaders, $bike['milestones']['total']);
        $this->assertEquals(['bike' => self::Leaders, 'car' => 3], array_intersect_key($bike['milestone_counts'], ['bike' => 0, 'car' => 0]));
        $this->assertArrayNotHasKey('car_milestones', $bike, 'The other tab is not sent.');
        $this->assertSame('Leader 30', $bike['milestones']['data'][0]['user']['name'], 'Highest qualifying volume first.');

        $car = $this->sectionData('/rewards?type=car');
        $this->assertCount(3, $car['milestones']['data']);
        $this->assertSame(1, $this->sectionData('/rewards?q=leader+12')['milestones']['total']);
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

    private function milestone(User $user, string $type, int $volume): void
    {
        DB::table('reward_milestone_progress')->insert([
            'user_id' => $user->id, 'milestone_type' => $type, 'target_amount' => '5000000.00', 'leg_cap_amount' => '800000.00',
            'raw_team_volume' => $volume, 'qualifying_volume' => $volume, 'leg_breakdown' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function sectionData(string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json('props.data');
    }
}
