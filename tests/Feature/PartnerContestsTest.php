<?php

namespace Tests\Feature;

use App\Domain\Orders\PartnerContests;
use App\Models\Order;
use App\Models\PartnerContest;
use App\Models\User;
use App\Support\DashboardManagement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerContestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-10 06:00:00');
    }

    public function test_owner_defines_a_contest_and_partners_see_it_change_live(): void
    {
        $owner = User::factory()->create(['role' => 'md', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim']);
        $subDealer = User::factory()->subDealer()->create(['name' => 'Karim']);
        $this->order($dealer, 'approved', '300.00');
        $this->order($subDealer, 'delivered', '500.00');

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/contests', $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($dealer)->onPortal('dealer')->get('/contests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('data.contests.can_manage', false)
                ->where('data.contests.contests.0.status', 'running')
                ->where('data.contests.contests.0.standings.0.name', 'Karim')
                ->where('data.contests.contests.0.standings.0.value', '500.00')
                ->where('data.contests.contests.0.standings.1.is_me', true)
                ->where('data.contests.contests.0.me.rank', 2));

        $this->order($dealer, 'approved', '400.00');
        $this->actingAs($dealer)->onPortal('dealer')->get('/contests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.contests.contests.0.me.rank', 1)
                ->where('data.contests.contests.0.standings.0.value', '700.00'));
    }

    public function test_only_confirmed_real_orders_inside_the_period_count(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $contest = $this->contest($owner, 'purchases');
        $this->order($dealer, 'approved', '100.00');
        $this->order($dealer, 'cancelled', '900.00');
        $this->order($dealer, 'submitted', '900.00');
        $this->order($dealer, 'approved', '900.00', at: '2026-10-31 12:00:00');
        $opening = $this->order($dealer, 'delivered', '900.00');
        DB::table('orders')->where('id', $opening->id)->update(['is_opening_balance' => true]);
        // 18:30 UTC on 31 October is already 1 November in Dhaka, so this order is inside the period.
        $this->order($dealer, 'approved', '10.00', at: '2026-10-31 18:30:00');

        $board = $this->boardFor($dealer, $contest);

        $this->assertSame('110.00', $board['standings'][0]['value']);
    }

    public function test_number_of_orders_and_new_partners_can_be_the_metric(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, 'approved', '100.00');
        $this->order($dealer, 'delivered', '5.00');
        User::factory()->count(3)->subDealer()->create(['sponsor_id' => $dealer->id]);
        User::factory()->subDealer()->create(['sponsor_id' => $dealer->id, 'created_at' => '2026-09-01 00:00:00']);

        $this->assertSame('2', $this->boardFor($dealer, $this->contest($owner, 'orders'))['standings'][0]['value']);
        $this->assertSame('3', $this->boardFor($dealer, $this->contest($owner, 'new_partners'))['standings'][0]['value']);
    }

    public function test_hidden_amounts_setting_hides_taka_from_partners_but_not_the_owner(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, 'approved', '100.00');
        $contest = $this->contest($owner, 'purchases');
        DashboardManagement::save([...DashboardManagement::get(), 'top_sellers_show_amounts' => false]);

        $partnerBoard = $this->boardFor($dealer, $contest);
        $this->assertSame(1, $partnerBoard['standings'][0]['rank']);
        $this->assertNull($partnerBoard['standings'][0]['value']);
        $this->assertNull($partnerBoard['me']['value']);
        $this->assertSame('100.00', $this->boardFor($owner, $contest)['standings'][0]['value']);
    }

    public function test_phone_numbers_are_never_in_the_standings(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create(['phone' => '01711223344']);
        $this->order($dealer, 'approved', '100.00');
        $this->contest($owner, 'purchases');

        $this->assertStringNotContainsString('01711223344', (string) json_encode(app(PartnerContests::class)->forViewer($owner)));
    }

    public function test_partners_do_not_see_a_contest_before_it_starts_but_the_owner_does(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create();
        PartnerContest::query()->create([...$this->payload(), 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31', 'created_by' => $owner->id]);

        $this->assertSame([], app(PartnerContests::class)->forViewer($dealer)['contests']);
        $this->assertSame('upcoming', app(PartnerContests::class)->forViewer($owner)['contests'][0]['status']);
    }

    public function test_only_owners_manage_contests(): void
    {
        $dealer = User::factory()->dealer()->create();
        $staff = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $contest = $this->contest($owner, 'purchases');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/contests', $this->payload())->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->delete('/commands/contests/'.$contest->id)->assertForbidden();
        $this->actingAs($staff)->onPortal('sadmin')->post('/commands/contests', $this->payload())->assertForbidden();
        $this->actingAs($staff)->onPortal('sadmin')->get('/contests')->assertForbidden();
        $this->assertSame(1, PartnerContest::query()->count());

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/contests', [...$this->payload(), 'ends_on' => '2026-10-31'])
            ->assertSessionHasErrors('ends_on');
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/contests', [...$this->payload(), 'metric' => 'income'])
            ->assertSessionHasErrors('metric');
        $this->actingAs($owner)->onPortal('sadmin')->delete('/commands/contests/'.$contest->id)->assertRedirect();
        $this->assertSame(0, PartnerContest::query()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'contest.removed']);
    }

    public function test_a_contest_never_touches_commission_or_wallets(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, 'delivered', '100.00');
        $before = $this->moneyTableCounts();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/contests', $this->payload())->assertRedirect();
        $this->actingAs($dealer)->onPortal('dealer')->get('/contests')->assertOk();
        Carbon::setTestNow('2026-12-05 06:00:00');
        $this->actingAs($dealer)->onPortal('dealer')->get('/contests')->assertOk();

        $this->assertSame($before, $this->moneyTableCounts());
    }

    public function test_query_count_does_not_grow_with_partners_or_orders(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, 'approved', '100.00');
        $this->contest($owner, 'purchases');
        $few = $this->queriesFor($dealer);

        foreach (User::factory()->count(6)->dealer()->create() as $partner) {
            $this->order($partner, 'approved', '50.00');
        }
        $this->order($dealer, 'approved', '10.00');

        $this->assertSame($few, $this->queriesFor($dealer));
    }

    private function queriesFor(User $viewer): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(PartnerContests::class)->forViewer($viewer);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return array<string, int> */
    private function moneyTableCounts(): array
    {
        $counts = [];
        foreach (['commission_allocations', 'commission_batches', 'wallets', 'withdrawals', 'ledger_entries'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    private function boardFor(User $viewer, PartnerContest $contest): array
    {
        return collect(app(PartnerContests::class)->forViewer($viewer)['contests'])->firstWhere('id', (int) $contest->id);
    }

    private function contest(User $owner, string $metric): PartnerContest
    {
        return PartnerContest::query()->create([...$this->payload(), 'metric' => $metric, 'created_by' => $owner->id]);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'title' => 'November push',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'metric' => 'purchases',
            'prize_text' => 'A motorbike helmet for first place',
        ];
    }

    private function order(User $buyer, string $status, string $total, ?string $at = null): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        if ($at !== null) {
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);
        }

        return $order;
    }
}
