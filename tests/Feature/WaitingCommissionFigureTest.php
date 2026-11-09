<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerEarningsPresenter;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A partner's commission still in its waiting period: the total, and the date stored on each row.
 */
class WaitingCommissionFigureTest extends TestCase
{
    use RefreshDatabase;

    private User $dealer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->order = Order::create(['number' => 'ORD-WAIT-'.Str::random(6), 'user_id' => $this->dealer->id, 'status' => 'delivered', 'channel' => 'dealer', 'currency' => 'BDT',
            'subtotal' => '1000.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => '1000.00', 'paid' => '1000.00', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random())]);
    }

    public function test_the_home_figure_shows_the_total_and_each_date_in_business_time(): void
    {
        $this->allocation('550.00', 'maturing', '2026-11-12T10:00:00+00:00');
        $this->allocation('50.00', 'maturing', '2026-11-12T17:59:00+00:00');
        // 20:00 UTC on the 12th is already the 13th in Dhaka.
        $this->allocation('100.25', 'maturing', '2026-11-12T20:00:00+00:00');

        $waiting = app(PartnerDashboardHome::class)->for($this->dealer)['wallet']['commission_waiting'];

        $this->assertSame('700.25', $waiting['total']);
        $this->assertSame([
            ['ready_on' => '2026-11-12', 'amount' => '600.00'],
            ['ready_on' => '2026-11-13', 'amount' => '100.25'],
        ], $waiting['amounts']);
    }

    public function test_only_the_partners_own_waiting_money_counts(): void
    {
        $this->allocation('10.00', 'maturing', '2026-11-12T10:00:00+00:00');
        $this->allocation('20.00', 'available', '2026-11-12T10:00:00+00:00');
        $this->allocation('40.00', 'held', '2026-11-12T10:00:00+00:00');
        $this->allocation('80.00', 'reversed', '2026-11-12T10:00:00+00:00');
        $this->allocation('160.00', 'maturing', '2026-11-12T10:00:00+00:00', User::factory()->dealer()->create());

        $waiting = app(PartnerDashboardHome::class)->for($this->dealer)['wallet']['commission_waiting'];

        $this->assertSame('10.00', $waiting['total']);
        $this->assertSame([['ready_on' => '2026-11-12', 'amount' => '10.00']], $waiting['amounts']);
    }

    public function test_no_waiting_money_reads_as_zero_with_no_dates(): void
    {
        $this->assertSame(['total' => '0.00', 'amounts' => []], app(PartnerEarningsPresenter::class)->waitingCommission($this->dealer));
    }

    public function test_a_balance_brought_over_from_the_old_books_has_no_date(): void
    {
        $this->allocation('10.00', 'maturing', '2026-11-12T10:00:00+00:00');
        $importId = (string) Str::uuid();
        DB::table('data_import_batches')->insert(['id' => $importId, 'type' => 'opening_balances', 'status' => 'applied', 'filename' => 'x.xlsx', 'file_path' => 'x', 'file_hash' => 'x', 'source_reference' => 'x', 'rows' => '[]', 'summary' => '{}', 'created_by' => User::factory()->staff('finance')->create()->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('opening_balance_entries')->insert(['import_id' => $importId, 'user_id' => $this->dealer->id, 'reference' => 'OPEN-1', 'as_of_date' => '2026-10-01', 'available' => '0.00', 'held' => '30.00', 'held_released' => '5.00', 'created_at' => now(), 'updated_at' => now()]);

        $waiting = app(PartnerEarningsPresenter::class)->waitingCommission($this->dealer);

        $this->assertSame('35.00', $waiting['total']);
        $this->assertSame([['ready_on' => '2026-11-12', 'amount' => '10.00'], ['ready_on' => null, 'amount' => '25.00']], $waiting['amounts']);
    }

    public function test_each_earnings_row_carries_its_date_only_while_it_waits(): void
    {
        $waiting = $this->allocation('10.00', 'maturing', '2026-11-12T10:00:00+00:00');
        $ready = $this->allocation('20.00', 'available', '2026-11-01T10:00:00+00:00');

        $rows = collect(app(PartnerEarningsPresenter::class)->ledgerRows($this->dealer, null, null))->keyBy('id');

        $this->assertSame('2026-11-12T10:00:00+00:00', $rows[$waiting->id]['waiting_until']);
        $this->assertNull($rows[$ready->id]['waiting_until']);
    }

    public function test_the_home_and_my_money_pages_send_the_figures(): void
    {
        $this->allocation('550.00', 'maturing', CarbonImmutable::now()->addDays(3)->toIso8601String());
        DB::table('journal_entries')->insert(['id' => (string) Str::uuid(), 'event_key' => 'commissions.order.'.$this->order->id, 'description' => 'Commission posted', 'created_at' => now()]);
        DB::table('wallets')->insert(['user_id' => $this->dealer->id, 'available' => '0.00', 'held' => '550.00', 'commission_held' => '550.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.partner_home.wallet.commission_waiting.total', '550.00')
                ->has('data.partner_home.wallet.commission_waiting.amounts', 1));
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/my-money')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.waiting', '550.00')
                ->where('table.rows.data.0.amount', '550.00')
                ->whereType('table.rows.data.0.waiting_until', 'string'));
    }

    private function allocation(string $amount, string $status, string $dueAt, ?User $partner = null): CommissionAllocation
    {
        return CommissionAllocation::query()->create(['order_id' => $this->order->id, 'user_id' => ($partner ?? $this->dealer)->id, 'category' => 'direct_referral', 'rate_percentage' => 5,
            'cash_basis' => '1000.00', 'amount' => $amount, 'original_amount' => $amount, 'status' => $status, 'metadata' => ['maturation_due_at' => $dueAt]]);
    }
}
