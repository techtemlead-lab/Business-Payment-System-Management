<?php

namespace Tests\Feature;

use App\Models\BusinessConfig;
use App\Models\Dealer;
use App\Models\LedgerEntry;
use App\Models\RankDefinition;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\ModeManager;
use App\Services\PayoutService;
use App\Services\WithdrawalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('pgsql')]
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency tests require the dedicated PostgreSQL test database.');
        }
    }

    private function parallel(array $operations): array
    {
        $files = [];
        $children = [];
        DB::disconnect();
        foreach ($operations as $operation) {
            $file = tempnam(sys_get_temp_dir(), 'nexora-test-');
            $files[] = $file;
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    $operation();
                    file_put_contents($file, 'ok');
                } catch (\Throwable $e) {
                    file_put_contents($file, $e::class.': '.$e->getMessage());
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::purge();
        $results = [];
        foreach ($files as $file) {
            $results[] = file_get_contents($file);
            unlink($file);
        }

        return $results;
    }

    public function test_concurrent_mode_changes_allow_only_one_revision(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        RankDefinition::create(['name' => 'Approved rank', 'eligibility' => 'Company-approved status criteria', 'actor_id' => $admin->id, 'status' => 'approved', 'effect' => 'status_only']);
        $results = $this->parallel([fn () => (new ModeManager)->change('RANK_BASED', 1, 'Concurrent change A'), fn () => (new ModeManager)->change('RANK_BASED', 1, 'Concurrent change B')]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'ok')));
        $this->assertSame('RANK_BASED', BusinessConfig::find(1)->mode);
        $this->assertSame(2, BusinessConfig::find(1)->mode_revision);
        $this->assertDatabaseCount('business_config', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_concurrent_reversals_cannot_reverse_an_earning_twice(): void
    {
        $d = Dealer::factory()->create();
        $entry = LedgerEntry::create(['dealer_id' => $d->id, 'amount_minor' => 10000, 'kind' => 'earned', 'business_reference' => 'fixture-1', 'reason' => 'Test fixture only', 'created_at' => now()]);
        $results = $this->parallel([fn () => (new LedgerService)->reverse($entry->id, 'reverse-a', 'Correction A'), fn () => (new LedgerService)->reverse($entry->id, 'reverse-b', 'Correction B')]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'ok')));
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame(0, (new LedgerService)->balances($d->id)['available_minor']);
    }

    public function test_concurrent_unconfigured_payouts_never_reserve_or_pay_funds(): void
    {
        $d = Dealer::factory()->create();
        LedgerEntry::create(['dealer_id' => $d->id, 'amount_minor' => 10000, 'kind' => 'earned', 'business_reference' => 'fixture-1', 'reason' => 'Test fixture only', 'created_at' => now()]);
        $results = $this->parallel([fn () => app(PayoutService::class)->request($d->id, 10000, 'payout-a'), fn () => app(PayoutService::class)->request($d->id, 10000, 'payout-b')]);
        $this->assertSame(0, count(array_filter($results, fn ($r) => $r === 'ok')));
        $this->assertDatabaseCount('payouts', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(10000, (new LedgerService)->balances($d->id)['available_minor']);
    }

    public function test_concurrent_same_invoice_creates_one_purchase_and_one_earning(): void
    {
        $dealer = Dealer::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $payload = ['dealer_code' => $dealer->code, 'reference' => 'CONCURRENT-INVOICE', 'product_name' => 'Test product', 'quantity' => 2, 'unit_mrp' => '375.00', 'amount_paid' => '600.00', 'purchased_on' => now()->toDateString(), 'password' => 'password'];
        $operation = fn () => $this->post('/purchases', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $results = $this->parallel([$operation, $operation]);
        $this->assertSame(['ok', 'ok'], $results);
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(37500, (new LedgerService)->balances($dealer->id)['available_minor']);
    }

    public function test_concurrent_withdrawals_cannot_reserve_the_same_available_balance(): void
    {
        $dealer = Dealer::factory()->create();
        LedgerEntry::create(['dealer_id' => $dealer->id, 'amount_minor' => 10000, 'kind' => 'earned', 'business_reference' => 'fixture-1', 'reason' => 'Test fixture', 'created_at' => now()]);
        $results = $this->parallel([fn () => app(WithdrawalService::class)->request($dealer->id, 10000, '9e16d00e-984d-4c51-95e6-ce91230d871e', null), fn () => app(WithdrawalService::class)->request($dealer->id, 10000, '9e16d00e-984d-4c51-95e6-ce91230d871f', null)]);
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result === 'ok')));
        $this->assertDatabaseCount('withdrawal_requests', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame(0, (new LedgerService)->balances($dealer->id)['available_minor']);
    }

    public function test_concurrent_duplicate_withdrawal_reserves_only_once(): void
    {
        $dealer = Dealer::factory()->create();
        LedgerEntry::create(['dealer_id' => $dealer->id, 'amount_minor' => 10000, 'kind' => 'earned', 'business_reference' => 'fixture-1', 'reason' => 'Test fixture', 'created_at' => now()]);
        $operation = fn () => app(WithdrawalService::class)->request($dealer->id, 10000, '9e16d00e-984d-4c51-95e6-ce91230d871e', null);
        $results = $this->parallel([$operation, $operation]);
        $this->assertSame(['ok', 'ok'], $results);
        $this->assertDatabaseCount('withdrawal_requests', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame(10000, (new LedgerService)->balances($dealer->id)['reserved_minor']);
    }

    public function test_database_rejects_direct_ledger_history_mutation(): void
    {
        $d = Dealer::factory()->create();
        $entry = LedgerEntry::create(['dealer_id' => $d->id, 'amount_minor' => 10000, 'kind' => 'earned', 'business_reference' => 'fixture-1', 'reason' => 'Test fixture only', 'created_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('ledger_entries')->where('id', $entry->id)->update(['amount_minor' => 0]);
    }

    public function test_postgresql_dealer_search_is_case_insensitive(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dealer = Dealer::factory()->create(['business_name' => 'MixedCase Trading']);
        $this->actingAs($admin)->get('/dealers?search=mixedcase')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Dealers')->has('dealers.data', 1)->where('dealers.data.0.id', $dealer->id));
    }
}
