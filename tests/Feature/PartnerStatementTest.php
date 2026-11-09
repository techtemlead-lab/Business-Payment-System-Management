<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerWalletStatement;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class PartnerStatementTest extends TestCase
{
    use RefreshDatabase;

    private const Range = 'from=2026-03-01&to=2026-03-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_ledger_pages_oldest_first_with_running_balance_and_whole_range_totals(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->entry($dealer, ['wallet_liability' => ['100.00', '0.00']], 'Before the range', '2026-02-27 10:00:00');
        foreach (range(1, 30) as $index) {
            $this->entry($dealer, ['wallet_liability' => ['10.00', '0.00']], 'Commission '.$index, CarbonImmutable::parse('2026-03-05 06:00:00')->addMinutes($index));
        }
        // Held money released to available inside one entry: the balance does not change.
        $this->entry($dealer, ['commission_held_liability' => ['0.00', '40.00'], 'wallet_liability' => ['40.00', '0.00']], 'Hold released', '2026-03-06 06:00:00');
        $this->entry($dealer, ['wallet_liability' => ['0.00', '55.50']], 'Withdrawal paid', '2026-03-07 06:00:00');
        $this->entry($dealer, ['wallet_liability' => ['999.00', '0.00']], 'After the range', '2026-04-01 06:00:00');

        $first = $this->ledger($dealer);
        $second = $this->ledger($dealer, 2);

        $this->assertSame('100.00', $first['opening_balance']);
        $this->assertSame('300.00', $first['total_in']);
        $this->assertSame('55.50', $first['total_out']);
        $this->assertSame('344.50', $first['closing_balance']);
        $this->assertSame(31, $first['line_count'], 'Thirty commissions and the withdrawal; the hold release is left out.');
        $this->assertSame(31, $first['lines']['total']);
        $this->assertCount(25, $first['lines']['data']);
        $this->assertSame('Commission 1', $first['lines']['data'][0]['description']);
        $this->assertSame('110.00', $first['lines']['data'][0]['balance']);
        $this->assertCount(6, $second['lines']['data']);
        $this->assertSame('360.00', $second['lines']['data'][0]['balance'], 'Page two carries on from the balance at the end of page one.');
        $last = end($second['lines']['data']);
        $this->assertSame('Withdrawal paid', $last['description']);
        $this->assertSame('55.50', $last['money_out']);
        $this->assertSame('0.00', $last['money_in']);
        $this->assertSame('344.50', $last['balance']);
    }

    public function test_lines_name_the_order_or_withdrawal_they_came_from(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $order = Order::create(['number' => 'ORD-STMT0001', 'user_id' => $dealer->id, 'status' => 'delivered', 'total' => '500.00', 'paid' => '500.00', 'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random())]);
        $withdrawal = (string) Str::uuid();
        $this->entry($dealer, ['wallet_liability' => ['25.00', '0.00']], 'Commission earned', '2026-03-03 06:00:00', 'commissions.order.'.$order->id);
        $this->entry($dealer, ['wallet_liability' => ['0.00', '20.00']], 'Payout sent', '2026-03-04 06:00:00', 'manual-payout:'.$withdrawal);

        $lines = $this->ledger($dealer)['lines']['data'];

        $this->assertSame('ORD-STMT0001', $lines[0]['reference']);
        $this->assertSame($order->id, $lines[0]['order_id']);
        $this->assertSame('WD-'.strtoupper(substr($withdrawal, 0, 8)), $lines[1]['reference']);
        $this->assertSame($withdrawal, $lines[1]['withdrawal_id']);
    }

    public function test_partners_see_only_their_own_ledger_and_staff_need_accounts_or_finance(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $subDealer = User::factory()->subDealer()->create(['status' => 'active']);
        $this->entry($dealer, ['wallet_liability' => ['75.00', '0.00']], 'Dealer credit', '2026-03-03 06:00:00');
        $this->entry($subDealer, ['wallet_liability' => ['5.00', '0.00']], 'Sub-dealer credit', '2026-03-03 06:00:00');

        $this->assertSame('5.00', $this->ledger($subDealer)['closing_balance']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/accounts/'.$subDealer->id.'/statement')->assertRedirect('/my-money');

        foreach (['account_manager', 'finance'] as $role) {
            $staff = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($staff)->onPortal('manage')->get('/accounts/'.$dealer->id.'/statement')->assertRedirect('/treasury/partners/'.$dealer->id);
            $this->actingAs($staff)->onPortal('manage')->get('/treasury/partners/'.$dealer->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('TreasuryPartner'));
        }
        $storekeeper = User::factory()->create(['role' => 'inventory', 'status' => 'active']);
        $this->actingAs($storekeeper)->onPortal('manage')->get('/treasury/partners/'.$dealer->id)->assertForbidden();
        $this->actingAs($storekeeper)->onPortal('manage')->get('/accounts/'.$dealer->id.'/statement/xlsx')->assertForbidden();
        $owner = User::factory()->staff()->create();
        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury/partners/'.$storekeeper->id)->assertNotFound();
    }

    public function test_excel_and_pdf_hold_the_whole_range_with_opening_and_closing_balances(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->entry($dealer, ['wallet_liability' => ['100.00', '0.00']], 'Before the range', '2026-02-27 10:00:00');
        foreach (range(1, 30) as $index) {
            $this->entry($dealer, ['wallet_liability' => ['10.00', '0.00']], 'Commission '.$index, CarbonImmutable::parse('2026-03-05 06:00:00')->addMinutes($index));
        }
        $this->entry($dealer, ['wallet_liability' => ['0.00', '55.50']], 'Withdrawal paid', '2026-03-07 06:00:00');

        $response = $this->actingAs($dealer)->onPortal('dealer')->get('/statement/xlsx?'.self::Range)
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $rows = $this->rows($response);

        $this->assertCount(35, $rows, 'Header, opening, 31 lines, closing and the totals row.');
        $this->assertSame(__('portal.opening_balance'), $rows[1][1]);
        $this->assertSame(100.0, (float) $rows[1][5]);
        $this->assertSame(__('portal.closing_balance'), $rows[33][1]);
        $this->assertSame(344.5, (float) $rows[33][5]);
        $this->assertSame(300.0, (float) $rows[34][3]);
        $this->assertSame(55.5, (float) $rows[34][4]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/statement/pdf?'.self::Range)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertSame(2, DB::table('audit_events')->where('action', 'report.exported')->where('subject', 'partner-statement')->count());
    }

    public function test_statement_query_count_does_not_grow_with_the_number_of_lines(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->entry($dealer, ['wallet_liability' => ['10.00', '0.00']], 'One', '2026-03-05 06:00:00', 'commissions.order.'.Str::uuid());
        $this->ledger($dealer);
        $count = $this->countQueries(fn () => $this->ledger($dealer));

        foreach (range(1, 20) as $index) {
            $this->entry($dealer, ['wallet_liability' => ['10.00', '0.00']], 'More '.$index, CarbonImmutable::parse('2026-03-06 06:00:00')->addMinutes($index), 'commissions.order.'.Str::uuid());
        }

        $this->assertSame($count, $this->countQueries(fn () => $this->ledger($dealer)));
    }

    /**
     * Posts one journal entry with a line per account: [credit, debit].
     *
     * @param  array<string, array{0: string, 1: string}>  $lines
     */
    private function entry(User $partner, array $lines, string $description, mixed $createdAt, ?string $eventKey = null): void
    {
        $entry = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => $eventKey ?? 'test.wallet.'.$entry, 'description' => $description, 'created_at' => $createdAt]);
        foreach ($lines as $account => [$credit, $debit]) {
            DB::table('journal_lines')->insert(['entry_id' => $entry, 'user_id' => $partner->id, 'account' => $account, 'debit' => $debit, 'credit' => $credit]);
        }
    }

    /** @return array<string, mixed> */
    private function ledger(User $partner, int $page = 1): array
    {
        Paginator::currentPageResolver(fn (): int => $page);
        $ledger = app(PartnerWalletStatement::class)->ledger($partner, '2026-03-01', '2026-03-31');

        return json_decode((string) json_encode($ledger), true);
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
