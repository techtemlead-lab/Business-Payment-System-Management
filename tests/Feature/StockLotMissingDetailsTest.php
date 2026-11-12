<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockLotDetails;
use App\Domain\Inventory\StockLotReports;
use App\Domain\Inventory\StockLots;
use App\Domain\Inventory\StockService;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockCountLine;
use App\Models\StockCountSession;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockLotMissingDetailsTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $branch;

    private User $counter;

    private User $approver;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->branch = CompanyBranch::factory()->create();
        $this->counter = User::factory()->staff('inventory')->create();
        $this->approver = User::factory()->staff('inventory')->create();
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    public function test_a_count_surplus_with_no_batch_is_flagged_on_the_batches_page(): void
    {
        $lot = $this->surplusLot();

        $this->assertNull($lot->batch_number);
        $this->assertNull($lot->expires_on);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-lots?all_branches=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.stock_lots.missing_details_count', 1));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-lots?tab=details&all_branches=1')
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.stock_lots.tab', 'details')
                ->count('data.stock_lots.missing_details', 1)
                ->where('data.stock_lots.missing_details.0.id', $lot->id)
                ->where('data.stock_lots.missing_details.0.quantity', 4));
    }

    public function test_a_direct_stock_in_with_a_blank_batch_is_flagged(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'batch_number' => null, 'expires_on' => null]);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/stock', [
            'product_id' => $product->id, 'branch_id' => $this->branch->id, 'kind' => BranchOperations::KindProductIn, 'quantity' => 5,
            'reason' => 'supplier_receipt', 'reference' => (string) Str::uuid(), 'expires_on' => now()->addYear()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $lot = StockLot::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(1, app(StockLotReports::class)->missingDetailsCount($this->allBranches()));

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/stock-lots/'.$lot->id.'/details', ['batch_number' => 'ON-THE-PACK'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, app(StockLotReports::class)->missingDetailsCount($this->allBranches()));
    }

    public function test_a_direct_stock_in_with_its_details_is_not_flagged(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'batch_number' => null, 'expires_on' => null]);
        app(StockService::class)->stockIn($this->owner, [
            'product_id' => $product->id, 'quantity' => 5, 'reference' => (string) Str::uuid(), 'branch_id' => $this->branch->id,
            'batch_number' => 'COMPLETE-1', 'expires_on' => now()->addYear()->toDateString(),
        ]);

        $this->assertSame(0, app(StockLotReports::class)->missingDetailsCount($this->allBranches()));
    }

    public function test_opening_stock_and_goods_moved_between_branches_are_not_flagged(): void
    {
        foreach ([StockLots::SourceOpening, StockLots::SourceTransfer, StockLots::SourceReturn] as $source) {
            StockLot::factory()->create(['branch_id' => $this->branch->id, 'batch_number' => null, 'expires_on' => null, 'source' => $source]);
        }

        $this->assertSame(0, app(StockLotReports::class)->missingDetailsCount($this->allBranches()));
    }

    public function test_a_lot_that_has_sold_out_is_no_longer_flagged(): void
    {
        $lot = $this->surplusLot();
        $lot->forceFill(['quantity' => 0])->save();

        $this->assertSame(0, app(StockLotReports::class)->missingDetailsCount($this->allBranches()));
    }

    public function test_staff_fill_in_the_details_and_near_expiry_then_warns_about_the_lot(): void
    {
        $lot = $this->surplusLot();
        $expiry = now()->addDays(20)->toDateString();

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/stock-lots/'.$lot->id.'/details', ['batch_number' => 'FOUND-1', 'expires_on' => $expiry])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('FOUND-1', $lot->refresh()->batch_number);
        $this->assertSame($expiry, $lot->expires_on->toDateString());
        $this->assertSame(0, app(StockLotReports::class)->missingDetailsCount($this->allBranches()));
        $this->assertDatabaseHas('audit_events', ['action' => 'stock.lot_details_filled', 'subject' => (string) $lot->id]);

        $near = app(StockLotReports::class)->expiryLists($this->allBranches(), 30)['near'];
        $this->assertSame([$lot->id], array_column($near, 'id'));
    }

    public function test_one_detail_can_be_filled_now_and_the_other_later(): void
    {
        $lot = $this->surplusLot();

        app(StockLotDetails::class)->fillIn($this->owner, $lot, ['batch_number' => 'FOUND-2']);

        // The expiry date is optional: with the batch number written nothing is left waiting,
        // but the lot stays on the list so the date can still be added.
        $this->assertSame(0, app(StockLotReports::class)->missingDetailsCount($this->allBranches()));
        $this->assertSame([$lot->id], array_column(app(StockLotReports::class)->lotsMissingDetails($this->allBranches()), 'id'));

        app(StockLotDetails::class)->fillIn($this->owner, $lot, ['expires_on' => now()->addYear()->toDateString()]);

        $this->assertSame([], app(StockLotReports::class)->lotsMissingDetails($this->allBranches()));
    }

    public function test_a_real_past_expiry_can_be_recorded_so_the_goods_are_blocked(): void
    {
        $lot = $this->surplusLot();

        app(StockLotDetails::class)->fillIn($this->owner, $lot, ['expires_on' => now()->subDays(5)->toDateString()]);

        $this->assertTrue($lot->refresh()->isExpired());
    }

    public function test_details_already_on_a_lot_are_never_overwritten(): void
    {
        $lot = $this->surplusLot();
        app(StockLotDetails::class)->fillIn($this->owner, $lot, ['batch_number' => 'FIRST']);

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/stock-lots/'.$lot->id.'/details', ['batch_number' => 'SECOND'])
            ->assertSessionHasErrors('lot');

        $this->assertSame('FIRST', $lot->refresh()->batch_number);
    }

    public function test_something_must_be_entered_and_staff_without_stock_rights_are_refused(): void
    {
        $lot = $this->surplusLot();

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/stock-lots/'.$lot->id.'/details', [])
            ->assertSessionHasErrors(['batch_number', 'expires_on']);

        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')
            ->post('/commands/stock-lots/'.$lot->id.'/details', ['batch_number' => 'NOPE'])
            ->assertForbidden();

        $this->assertNull($lot->refresh()->batch_number);
    }

    private function allBranches(): BranchScope
    {
        return new BranchScope(true, []);
    }

    /** Runs a real count that finds four more units of a product with no batch on record. */
    private function surplusLot(): StockLot
    {
        $product = Product::factory()->create(['stock' => 5, 'batch_number' => null, 'expires_on' => null]);
        BranchProductStock::query()->create(['branch_id' => $this->branch->id, 'product_id' => $product->id, 'quantity' => 5]);

        $this->actingAs($this->counter)->onPortal('manage')->post('/commands/stock-counts', ['branch_id' => $this->branch->id])->assertSessionHasNoErrors();
        $session = StockCountSession::query()->latest('id')->firstOrFail();
        $line = StockCountLine::query()->where('stock_count_session_id', $session->id)->where('product_id', $product->id)->firstOrFail();
        $this->actingAs($this->counter)->onPortal('manage')->post("/commands/stock-counts/{$session->id}/lines", ['lines' => [['line_id' => $line->id, 'counted_quantity' => 9]]])->assertSessionHasNoErrors();
        $this->actingAs($this->counter)->onPortal('manage')->post("/commands/stock-counts/{$session->id}/submit")->assertSessionHasNoErrors();
        $this->actingAs($this->approver)->onPortal('manage')->post("/commands/stock-counts/{$session->id}/approve")->assertSessionHasNoErrors();

        return StockLot::query()->where('product_id', $product->id)->where('reference', $line->fresh()->stock_reference)->firstOrFail();
    }
}
