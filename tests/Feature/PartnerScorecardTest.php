<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchScope;
use App\Domain\Orders\PartnerScorecard;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                ->where('data.partner_scorecard.partners.data.0.name', 'Rahim')
                ->where('data.partner_scorecard.partners.data.0.orders', 5)
                ->where('data.partner_scorecard.partners.data.0.payments_verified', 1)
                ->where('data.partner_scorecard.partners.data.0.payments_verified_amount', '100.00')
                ->where('data.partner_scorecard.partners.data.0.payments_rejected', 1)
                ->where('data.partner_scorecard.partners.data.0.payments_pending', 1)
                ->where('data.partner_scorecard.partners.data.0.cancelled', 2)
                ->where('data.partner_scorecard.partners.data.0.returned', 1)
                ->missing('data.partner_scorecard.partners.data.0.phone'));
    }

    public function test_the_period_and_filters_narrow_the_figures(): void
    {
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim']);
        User::factory()->subDealer()->create(['name' => 'Karim']);
        $this->order($dealer, 'cancelled');
        $this->order($dealer, 'cancelled', at: '2026-09-15 12:00:00');
        $opening = $this->order($dealer, 'cancelled');
        DB::table('orders')->where('id', $opening->id)->update(['is_opening_balance' => true]);
        $scorecard = app(PartnerScorecard::class);

        $this->assertSame(2, $scorecard->page(['q' => 'rahim'], null)['partners']->items()[0]['cancelled']);
        $this->assertSame(1, $scorecard->page(['q' => 'rahim', 'period' => 'month'], null)['partners']->items()[0]['cancelled']);
        $subDealers = $scorecard->page(['buyer_role' => 'sub_dealer'], null)['partners']->items();
        $this->assertCount(1, $subDealers);
        $this->assertSame('Karim', $subDealers[0]['name']);
    }

    public function test_staff_tied_to_no_branch_see_no_partners(): void
    {
        User::factory()->dealer()->create();
        $scorecard = app(PartnerScorecard::class);

        $this->assertCount(1, $scorecard->page([], new BranchScope(true, []))['partners']->items());
        $this->assertCount(0, $scorecard->page([], new BranchScope(false, []))['partners']->items());
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

    private function queriesForPage(): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(PartnerScorecard::class)->page([], null);
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
