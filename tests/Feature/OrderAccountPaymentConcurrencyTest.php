<?php

namespace Tests\Feature;

use App\Domain\Finance\FinancialReadiness;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Two real PHP processes, each with its own database connection, spend the same balance at
 * the same moment. The data has to be committed for them to see it, so this class opts out
 * of the test transaction and asks the next test to rebuild the database afterwards.
 */
class OrderAccountPaymentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_two_orders_at_the_same_moment_on_one_balance_only_one_goes_through(): void
    {
        $this->enableFinancialPosting();
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 50]);
        DB::table('wallets')->insert(['user_id' => $dealer->id, 'available' => '150.00', 'created_at' => now(), 'updated_at' => now()]);
        $opening = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $opening, 'event_key' => 'test.opening.race', 'description' => 'Test opening balance', 'created_at' => now()]);
        DB::table('journal_lines')->insert([
            ['entry_id' => $opening, 'user_id' => $dealer->id, 'account' => 'wallet_liability', 'debit' => '0.00', 'credit' => '150.00'],
            ['entry_id' => $opening, 'user_id' => null, 'account' => 'commission_expense', 'debit' => '150.00', 'credit' => '0.00'],
        ]);
        $startAt = (string) (microtime(true) + 8);

        $processes = array_map(fn () => Process::path(base_path())->timeout(90)->start([
            PHP_BINARY, '-d', 'pcre.jit=0', 'tests/Support/concurrent_account_order.php', (string) $dealer->id, (string) $product->id, $startAt,
        ]), [1, 2]);
        $outcomes = array_map(fn ($process): string => trim($process->wait()->output()), $processes);
        sort($outcomes);

        $this->assertSame(['paid', 'refused'], $outcomes);
        $this->assertSame('50.00', (string) DB::table('wallets')->where('user_id', $dealer->id)->value('available'));
        $this->assertSame(1, Order::where('user_id', $dealer->id)->count());
        $this->assertSame(1, DB::table('receipts')->count());
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'like', 'order.account_pay.%')->count());
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch());
    }
}
