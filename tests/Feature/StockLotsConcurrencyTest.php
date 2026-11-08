<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockService;
use App\Domain\Orders\OrderService;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Two real PHP processes, each with its own database connection, race for the last units.
 * The data has to be committed for them to see it, so this class opts out of the test
 * transaction and asks the next test to rebuild the database afterwards.
 */
class StockLotsConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_two_dispatches_racing_for_the_last_units_send_them_only_once(): void
    {
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $hub->id]);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $product = Product::factory()->create(['stock' => 0]);
        $lot = app(StockService::class)->stockIn($owner, [
            'product_id' => $product->id, 'quantity' => 5, 'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id, 'batch_number' => 'RACE', 'expires_on' => now()->addYear()->toDateString(),
        ]);
        $first = $this->approvedOrder($owner, $dealer, $product);
        $second = $this->approvedOrder($owner, $dealer, $product);
        $startAt = (string) (microtime(true) + 8);

        $processes = array_map(fn (Order $order) => Process::path(base_path())->timeout(90)->start([
            PHP_BINARY, '-d', 'pcre.jit=0', 'tests/Support/concurrent_dispatch.php', (string) $owner->id, (string) $order->id, (string) $hub->id, '5', $startAt,
        ]), [$first, $second]);
        $outcomes = array_map(fn ($process): string => trim($process->wait()->output()), $processes);
        sort($outcomes);

        $this->assertSame(['refused', 'sent'], $outcomes);
        $this->assertSame(0, $lot->refresh()->quantity);
        $this->assertSame(0, (int) BranchProductStock::query()->where('branch_id', $hub->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertSame(1, DB::table('shipments')->count());
        $this->assertSame(-5, (int) DB::table('stock_lot_movements')->where('lot_id', $lot->id)->where('kind', 'dispatch')->sum('quantity'));
        $this->assertSame(0, (int) StockLot::query()->where('product_id', $product->id)->sum('quantity'));
    }

    private function approvedOrder(User $owner, User $dealer, Product $product): Order
    {
        $order = app(OrderService::class)->create($dealer, ['items' => [['product_id' => $product->id, 'quantity' => 5]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($owner, $order->fresh(), 'approved', 'Paid in full');

        return $order->fresh();
    }
}
