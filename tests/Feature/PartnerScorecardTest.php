<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchScope;
use App\Domain\Orders\PartnerScorecard;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerScorecardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-10 06:00:00');
    }

    public function test_staff_see_each_partners_payments_cancellations_and_returns(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim', 'phone' => '01711223344']);
        $delivered = $this->order($dealer, 'delivered');
        $this->claim($delivered, 'verified', '100.00', 'A');
        $this->claim($delivered, 'rejected', '100.00', 'B');
        $this->claim($this->order($dealer, 'approved'), 'pending', '50.00', 'C');
        $this->order($dealer, 'cancelled');
        $this->order($dealer, 'rejected');
        // Came back from the courier: the order is cancelled, but it counts only as a return.
        $this->shipment($this->order($dealer, 'cancelled'), 'returned');
        $this->shipment($delivered, 'dispatched');

        $this->actingAs($finance)->onPortal('manage')->get('/partner-scorecard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/manage/Dashboard')
                ->where('data.partner_scorecard.partners.rows.data.0.name', 'Rahim')
                ->where('data.partner_scorecard.partners.rows.data.0.orders', 5)
                ->where('data.partner_scorecard.partners.rows.data.0.payments_verified', 1)
                ->where('data.partner_scorecard.partners.rows.data.0.payments_verified_amount', '100.00')
                ->where('data.partner_scorecard.partners.rows.data.0.payments_rejected', 1)
                ->where('data.partner_scorecard.partners.rows.data.0.payments_pending', 1)
                ->where('data.partner_scorecard.partners.rows.data.0.cancelled', 2)
                ->where('data.partner_scorecard.partners.rows.data.0.returned', 1)
                ->missing('data.partner_scorecard.partners.rows.data.0.phone'));
    }

    public function test_the_period_and_filters_narrow_the_figures(): void
    {
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim']);
        User::factory()->subDealer()->create(['name' => 'Karim']);
        $this->order($dealer, 'cancelled');
        $this->order($dealer, 'cancelled', at: '2026-09-15 12:00:00');
        $opening = $this->order($dealer, 'cancelled');
        DB::table('orders')->where('id', $opening->id)->update(['is_opening_balance' => true]);

        $this->assertSame(2, $this->listed(['q' => 'rahim'])[0]['cancelled']);
        $this->assertSame(1, $this->listed(['q' => 'rahim', 'period' => 'month'])[0]['cancelled']);
        $subDealers = $this->listed(['buyer_role' => 'sub_dealer']);
        $this->assertCount(1, $subDealers);
        $this->assertSame('Karim', $subDealers[0]['name']);
    }

    public function test_search_finds_a_partner_by_name_or_code_on_the_page(): void
    {
        $finance = User::factory()->staff('finance')->create();
        User::factory()->dealer()->create(['name' => 'Rahim', 'dealer_code' => 'MNHC-1001']);
        User::factory()->dealer()->create(['name' => 'Karim', 'dealer_code' => 'MNHC-2002']);

        foreach (['rahim' => 'Rahim', '2002' => 'Karim'] as $words => $name) {
            $this->actingAs($finance)->onPortal('manage')->get('/partner-scorecard?q='.$words)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('data.partner_scorecard.partners.rows.data', 1)
                    ->where('data.partner_scorecard.partners.rows.data.0.name', $name)
                    ->where('data.partner_scorecard.partners.totals.count', 1));
        }
    }

    public function test_sorting_reaches_partners_beyond_the_first_page(): void
    {
        foreach (range(1, PartnerScorecard::PerPage + 5) as $index) {
            User::factory()->dealer()->create(['name' => 'Partner '.str_pad((string) $index, 3, '0', STR_PAD_LEFT)]);
        }
        $lastByName = User::factory()->dealer()->create(['name' => 'Zakir']);
        $this->order($lastByName, 'cancelled');
        $this->order($lastByName, 'cancelled');
        $this->claim($this->order($lastByName, 'delivered'), 'verified', '100.00', 'Z');

        $this->assertNotContains('Zakir', array_column($this->listed(), 'name'), 'By name this partner sits past the first page.');

        foreach (['cancelled' => 2, 'orders' => 3, 'payments_verified' => 1] as $sort => $figure) {
            $first = $this->listed(['sort' => $sort, 'dir' => 'desc'])[0];
            $this->assertSame('Zakir', $first['name'], "Sorting by {$sort} must search every page.");
            $this->assertSame($figure, $first[$sort]);
        }
        $this->assertSame('100.00', $this->listed(['sort' => 'payments_verified_amount', 'dir' => 'desc'])[0]['payments_verified_amount']);

        $lastPage = $this->listed(['sort' => 'cancelled', 'dir' => 'asc', 'page' => 2]);
        $this->assertSame('Zakir', $lastPage[array_key_last($lastPage)]['name']);
    }

    public function test_a_sort_the_table_does_not_offer_falls_back_to_the_name(): void
    {
        User::factory()->dealer()->create(['name' => 'Beta']);
        User::factory()->dealer()->create(['name' => 'Alpha']);

        $this->assertSame(['Alpha', 'Beta'], array_column($this->listed(['sort' => 'users.password']), 'name'));
    }

    public function test_staff_tied_to_no_branch_see_no_partners(): void
    {
        User::factory()->dealer()->create();

        $this->assertCount(1, $this->listed([], new BranchScope(true, [])));
        $this->assertCount(0, $this->listed([], new BranchScope(false, [])));
    }

    public function test_the_page_needs_reports_orders_or_finance_access(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($employee)->onPortal('manage')->get('/partner-scorecard')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/partner-scorecard')->assertNotFound();
    }

    public function test_query_count_does_not_grow_with_partners_or_orders(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->claim($this->order($dealer, 'approved'), 'verified', '10.00', 'A');
        $few = $this->queriesForPage();

        foreach (User::factory()->count(8)->dealer()->create() as $index => $partner) {
            $this->claim($this->order($partner, 'approved'), 'verified', '10.00', 'P'.$index);
            $this->shipment($this->order($partner, 'cancelled'), 'returned');
        }

        $this->assertSame($few, $this->queriesForPage());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function listed(array $query = [], ?BranchScope $branchScope = null): array
    {
        return app(PartnerScorecard::class)->page(Request::create('/partner-scorecard', 'GET', $query), $branchScope)['partners']['rows']->items();
    }

    private function queriesForPage(): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->listed();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function order(User $buyer, string $status, ?string $at = null): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        if ($at !== null) {
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);
        }

        return $order;
    }

    private function claim(Order $order, string $status, string $amount, string $reference): void
    {
        DB::table('payment_claims')->insert([
            'order_id' => $order->id,
            'channel' => 'bKash',
            'reference' => $reference.Str::upper(Str::random(6)),
            'amount' => $amount,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function shipment(Order $order, string $status): void
    {
        $actor = User::factory()->staff('finance')->create();
        DB::table('shipments')->insert([
            'order_id' => $order->id,
            'courier' => 'Test courier',
            'tracking' => Str::random(10),
            'status' => $status,
            'lines' => '[]',
            'actor_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
