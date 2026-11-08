<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockLotReports;
use App\Domain\Inventory\StockService;
use App\Domain\Inventory\StockTransfers;
use App\Domain\Orders\OrderInvoice;
use App\Domain\Orders\OrderService;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockLotsTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $hub;

    private CompanyBranch $otherBranch;

    private User $owner;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->otherBranch = CompanyBranch::query()->create(['code' => 'BR-TWO', 'name' => 'Branch Two', 'is_active' => true, 'is_stock_hub' => true, 'is_delivery_point' => true]);
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
    }

    public function test_stock_in_creates_a_lot_with_its_details_and_dispatch_takes_the_earliest_expiry_first(): void
    {
        $product = $this->product();
        $late = $this->stockIn($product, 5, 'LATE', '+300 days');
        $noExpiry = $this->stockIn($product, 5, 'NOEXP', null);
        $soon = $this->stockIn($product, 5, 'SOON', '+30 days', ['unit_cost' => '12.50', 'received_on' => '2026-11-01']);

        $this->assertSame('SOON', $soon->batch_number);
        $this->assertSame('12.50', $soon->unit_cost);
        $this->assertSame('2026-11-01', $soon->received_on->toDateString());

        $order = $this->approvedOrder($product, 7);
        $this->dispatchOrder($order, 7);

        $this->assertSame(0, $soon->refresh()->quantity);
        $this->assertSame(3, $late->refresh()->quantity);
        $this->assertSame(5, $noExpiry->refresh()->quantity);
        $lines = json_decode((string) DB::table('shipments')->where('order_id', $order->id)->value('lines'), true);
        $this->assertSame([['SOON', 5], ['LATE', 2]], array_map(fn (array $lot): array => [$lot['batch_number'], $lot['quantity']], $lines[0]['lots']));
        $shipmentId = (int) DB::table('shipments')->where('order_id', $order->id)->value('id');
        $this->assertDatabaseHas('stock_lot_movements', ['lot_id' => $soon->id, 'kind' => 'dispatch', 'quantity' => -5, 'order_id' => $order->id, 'shipment_id' => $shipmentId]);
        $this->assertLotsEqualTotals($product);
    }

    public function test_a_new_delivery_no_longer_overwrites_the_batch_the_product_shows(): void
    {
        $product = $this->product();
        $this->stockIn($product, 5, 'FIRST', '+30 days');
        $this->stockIn($product, 5, 'SECOND', '+300 days');

        $this->assertSame('FIRST', $product->fresh()->batch_number);
        $this->assertDatabaseCount('stock_lots', 2);
    }

    public function test_branch_totals_equal_the_lots_after_every_kind_of_movement(): void
    {
        $product = $this->product();
        $this->stockIn($product, 10, 'A', '+60 days');
        $this->assertLotsEqualTotals($product);
        $this->stockIn($product, 10, 'B', '+90 days');
        $this->assertLotsEqualTotals($product);

        $this->adjust($product, BranchOperations::KindProductOut, 3, 'damage');
        $this->assertLotsEqualTotals($product);
        $this->dispatchOrder($this->approvedOrder($product, 12), 12);
        $this->assertLotsEqualTotals($product);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_stock_moved_without_the_service_is_brought_into_the_lots(): void
    {
        $product = Product::factory()->create(['stock' => 20, 'batch_number' => 'OLD-1', 'expires_on' => now()->addYear()->toDateString()]);

        $this->dispatchOrder($this->approvedOrder($product, 4), 4);

        $lot = StockLot::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('opening', $lot->source);
        $this->assertSame('OLD-1', $lot->batch_number);
        $this->assertSame(16, $lot->quantity);
        $this->assertSame(16, $product->fresh()->stock);
        $this->assertLotsEqualTotals($product);
    }

    public function test_expired_lots_are_refused_with_a_clear_message_but_can_be_written_off(): void
    {
        $product = $this->product();
        $lot = $this->stockIn($product, 5, 'OLDBATCH', '+30 days');
        $lot->forceFill(['expires_on' => now()->subDays(2)->toDateString()])->save();
        $order = $this->approvedOrder($product, 5);

        try {
            $this->dispatchOrder($order, 5);
            $this->fail('Expired stock must not be sent.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('stock_lots.err_expired_refused', ['product' => $product->name, 'expired' => 5, 'batches' => 'OLDBATCH', 'usable' => 0]), $exception->errors()['shipment'][0]);
        }
        $this->assertSame(5, $lot->refresh()->quantity);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseCount('shipments', 0);

        $this->adjust($product, BranchOperations::KindProductOut, 5, 'expired');
        $this->assertSame(0, $lot->refresh()->quantity);
        $this->assertLotsEqualTotals($product);
    }

    public function test_an_ordinary_stock_out_cannot_take_expired_lots(): void
    {
        $product = $this->product();
        $this->stockIn($product, 5, 'OLD', '+30 days')->forceFill(['expires_on' => now()->subDay()->toDateString()])->save();

        $this->expectException(ValidationException::class);
        $this->adjust($product, BranchOperations::KindProductOut, 1, 'unofficial_out');
    }

    public function test_a_lot_is_still_good_on_its_expiry_date(): void
    {
        $product = $this->product();
        $lot = $this->stockIn($product, 2, 'TODAY', '+30 days');
        $lot->forceFill(['expires_on' => StockLot::today()])->save();

        $this->dispatchOrder($this->approvedOrder($product, 2), 2);

        $this->assertSame(0, $lot->refresh()->quantity);
    }

    public function test_courier_auto_booking_skips_when_only_expired_stock_is_left(): void
    {
        $product = $this->product();
        $this->stockIn($product, 5, 'OLD', '+30 days')->forceFill(['expires_on' => now()->subDay()->toDateString()])->save();
        $order = $this->paidOrder($product, 2, 'steadfast');
        app(IntegrationSettings::class)->saveProvider($this->owner, 'couriers', 'steadfast', [
            'enabled' => true, 'base_url' => 'https://courier.test/api/v1', 'api_key' => 'key', 'api_secret' => 'secret',
        ], app(IntegrationSettings::class)->snapshot()['version'], 'Courier test');

        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.courier_auto_skipped']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'approved']);
    }

    public function test_a_returned_parcel_goes_back_into_the_lots_it_left_from_exactly_once(): void
    {
        $product = $this->product();
        $soon = $this->stockIn($product, 3, 'SOON', '+30 days');
        $late = $this->stockIn($product, 5, 'LATE', '+300 days');
        $order = $this->approvedOrder($product, 6);
        $this->dispatchOrder($order, 6);
        $this->assertSame([0, 2], [$soon->refresh()->quantity, $late->refresh()->quantity]);
        $shipmentId = (int) DB::table('shipments')->where('order_id', $order->id)->value('id');
        DB::table('shipments')->where('id', $shipmentId)->update(['courier_status' => 'returned']);

        app(OrderService::class)->receiveReturnedParcel($this->owner, $order, $shipmentId, 'Back at the hub');
        app(OrderService::class)->receiveReturnedParcel($this->owner, $order, $shipmentId, 'Pressed twice');

        $this->assertSame([3, 5], [$soon->refresh()->quantity, $late->refresh()->quantity]);
        $this->assertDatabaseCount('stock_lots', 2);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertLotsEqualTotals($product);
        $this->assertSame([], app(StockLotReports::class)->dispatchedBatches([(int) $order->lines()->value('id')]));
    }

    public function test_a_parcel_sent_before_lots_existed_comes_back_as_a_lot_with_the_order_lines_batch(): void
    {
        $product = $this->product();
        $this->stockIn($product, 5, 'CURRENT', '+300 days');
        $order = $this->approvedOrder($product, 2);
        $this->dispatchOrder($order, 2);
        $shipment = DB::table('shipments')->where('order_id', $order->id)->first();
        $lines = json_decode((string) $shipment->lines, true);
        unset($lines[0]['lots']);
        DB::table('shipments')->where('id', $shipment->id)->update(['lines' => json_encode($lines), 'courier_status' => 'returned']);
        DB::table('order_lines')->where('order_id', $order->id)->update(['batch_number' => 'LEGACY', 'expires_on' => now()->addMonth()->toDateString()]);

        app(OrderService::class)->receiveReturnedParcel($this->owner, $order, (int) $shipment->id, null);

        $this->assertDatabaseHas('stock_lots', ['product_id' => $product->id, 'batch_number' => 'LEGACY', 'source' => 'return', 'quantity' => 2]);
        $this->assertLotsEqualTotals($product);
    }

    public function test_clinic_dispense_takes_earliest_expiry_and_appears_in_recall_without_patient_details(): void
    {
        [$prescription, $product] = $this->clinicPrescription(3);
        $late = $this->stockIn($product, 5, 'RXLATE', '+300 days');
        $soon = $this->stockIn($product, 5, 'RXSOON', '+30 days');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $this->hub->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $soon->refresh()->quantity);
        $this->assertSame(5, $late->refresh()->quantity);
        $this->assertDatabaseHas('stock_lot_movements', ['lot_id' => $soon->id, 'kind' => 'clinic_dispense', 'quantity' => -3, 'prescription_id' => $prescription->id]);
        $this->assertLotsEqualTotals($product);

        $recall = app(StockLotReports::class)->recall(new BranchScope(true, []), 'rxsoon');
        $this->assertSame($prescription->number, $recall['clinic'][0]['prescription_number']);
        $this->assertSame(3, $recall['clinic'][0]['quantity']);
        $this->assertArrayNotHasKey('patient_id', $recall['clinic'][0]);
        $this->assertArrayNotHasKey('patient_name', $recall['clinic'][0]);
    }

    public function test_clinic_dispense_refuses_expired_stock(): void
    {
        [$prescription, $product] = $this->clinicPrescription(1);
        $this->stockIn($product, 5, 'RXOLD', '+30 days')->forceFill(['expires_on' => now()->subDay()->toDateString()])->save();

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $this->hub->id])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertNotSame(Prescription::StatusDispensed, $prescription->refresh()->status);
    }

    public function test_invoice_shows_the_batches_really_sent_not_the_copy_from_order_time(): void
    {
        $product = $this->product();
        $this->stockIn($product, 3, 'REAL-A', '+30 days');
        $this->stockIn($product, 5, 'REAL-B', '+300 days');
        $order = $this->approvedOrder($product, 5);
        DB::table('order_lines')->where('order_id', $order->id)->update(['batch_number' => 'WRONG', 'expires_on' => now()->addYears(3)->toDateString()]);

        $before = app(OrderInvoice::class)->payload($order->fresh(), 'none');
        $this->assertSame([], $before['lines'][0]['batches'], 'Nothing has been sent yet, so no batch is promised.');

        $this->dispatchOrder($order, 5);

        $after = app(OrderInvoice::class)->payload($order->fresh(), 'none');
        $this->assertSame(['REAL-A', 'REAL-B'], array_column($after['lines'][0]['batches'], 'batch_number'));
        $this->assertSame([3, 2], array_column($after['lines'][0]['batches'], 'quantity'));
        $html = view('invoices.order-letter', $after)->render();
        $this->assertStringContainsString('REAL-A', $html);
        $this->assertStringNotContainsString('WRONG', $html);
    }

    public function test_an_invoice_for_goods_sent_before_lots_existed_keeps_the_order_copy(): void
    {
        $product = $this->product();
        $order = $this->approvedOrder($product, 2, 5);
        DB::table('order_lines')->where('order_id', $order->id)->update(['batch_number' => 'LEGACY', 'dispatched' => 2]);

        $payload = app(OrderInvoice::class)->payload($order->fresh(), 'none');

        $this->assertSame('LEGACY', $payload['lines'][0]['batches'][0]['batch_number']);
    }

    public function test_transfer_moves_stock_through_transit_and_keeps_the_batches(): void
    {
        $product = $this->product();
        $soon = $this->stockIn($product, 4, 'TR-SOON', '+30 days', ['unit_cost' => '10.00']);
        $late = $this->stockIn($product, 6, 'TR-LATE', '+300 days', ['unit_cost' => '20.00']);
        $transfers = app(StockTransfers::class);

        $transfer = $transfers->request($this->owner, ['product_id' => $product->id, 'quantity' => 7, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $this->otherBranch->id, 'note' => 'Top up']);
        $this->assertSame(StockTransfer::StatusRequested, $transfer->status);
        $this->assertSame(10, $product->fresh()->stock, 'A request moves nothing.');

        $transfers->send($this->owner, $transfer);
        $transfer->refresh();
        $this->assertSame(StockTransfer::StatusInTransit, $transfer->status);
        $this->assertSame(3, $this->branchTotal($this->hub, $product));
        $this->assertSame(0, $this->branchTotal($this->otherBranch, $product));
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame([0, 3], [$soon->refresh()->quantity, $late->refresh()->quantity]);

        $transfers->receive($this->owner, $transfer);
        $this->assertSame(StockTransfer::StatusReceived, $transfer->refresh()->status);
        $this->assertSame(7, $this->branchTotal($this->otherBranch, $product));
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_lots', ['branch_id' => $this->otherBranch->id, 'batch_number' => 'TR-SOON', 'quantity' => 4, 'unit_cost' => '10.00', 'parent_lot_id' => $soon->id, 'source' => 'transfer']);
        $this->assertDatabaseHas('stock_lots', ['branch_id' => $this->otherBranch->id, 'batch_number' => 'TR-LATE', 'quantity' => 3, 'unit_cost' => '20.00', 'parent_lot_id' => $late->id]);
        $this->assertLotsEqualTotals($product);
        foreach (['stock.transfer_requested', 'stock.transfer_sent', 'stock.transfer_received'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action, 'subject' => (string) $transfer->id]);
        }
        $this->assertDatabaseHas('stock_movements', ['reference' => 'transfer-out:'.$transfer->number, 'quantity' => -7, 'branch_id' => $this->hub->id]);
        $this->assertDatabaseHas('stock_movements', ['reference' => 'transfer-in:'.$transfer->number, 'quantity' => 7, 'branch_id' => $this->otherBranch->id]);
    }

    public function test_a_transfer_cannot_be_sent_twice_or_received_before_sending_and_only_a_request_can_be_cancelled(): void
    {
        $product = $this->product();
        $this->stockIn($product, 10, 'TR', '+60 days');
        $transfers = app(StockTransfers::class);
        $transfer = $transfers->request($this->owner, ['product_id' => $product->id, 'quantity' => 2, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $this->otherBranch->id]);

        $this->assertRefused(fn () => $transfers->receive($this->owner, $transfer), 'transfer');
        $transfers->send($this->owner, $transfer);
        $this->assertRefused(fn () => $transfers->send($this->owner, $transfer), 'transfer');
        $this->assertRefused(fn () => $transfers->cancel($this->owner, $transfer), 'transfer');
        $this->assertSame(8, $this->branchTotal($this->hub, $product));

        $second = $transfers->request($this->owner, ['product_id' => $product->id, 'quantity' => 1, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $this->otherBranch->id]);
        $transfers->cancel($this->owner, $second);
        $this->assertSame(StockTransfer::StatusCancelled, $second->refresh()->status);
        $this->assertSame(8, $this->branchTotal($this->hub, $product));
    }

    public function test_a_transfer_leaves_expired_lots_behind(): void
    {
        $product = $this->product();
        $this->stockIn($product, 5, 'OLD', '+30 days')->forceFill(['expires_on' => now()->subDay()->toDateString()])->save();
        $transfers = app(StockTransfers::class);
        $transfer = $transfers->request($this->owner, ['product_id' => $product->id, 'quantity' => 1, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $this->otherBranch->id]);

        $this->assertRefused(fn () => $transfers->send($this->owner, $transfer), 'transfer');
        $this->assertSame(StockTransfer::StatusRequested, $transfer->refresh()->status);
    }

    public function test_the_same_branch_cannot_be_both_ends_of_a_transfer(): void
    {
        $this->assertRefused(fn () => app(StockTransfers::class)->request($this->owner, ['product_id' => $this->product()->id, 'quantity' => 1, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $this->hub->id]), 'destination_branch_id');
    }

    public function test_a_partner_cannot_move_stock_between_branches(): void
    {
        $product = $this->product();
        $this->expectException(HttpException::class);
        app(StockTransfers::class)->request($this->dealer, ['product_id' => $product->id, 'quantity' => 1, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $this->otherBranch->id]);
    }

    public function test_stock_in_refuses_a_past_expiry_and_a_repeated_reference(): void
    {
        $product = $this->product();
        $this->assertRefused(fn () => app(StockService::class)->stockIn($this->owner, ['product_id' => $product->id, 'quantity' => 1, 'reference' => (string) Str::uuid(), 'branch_id' => $this->hub->id, 'expires_on' => '2020-01-01']), 'expires_on');

        $reference = (string) Str::uuid();
        $data = ['product_id' => $product->id, 'quantity' => 1, 'reference' => $reference, 'branch_id' => $this->hub->id, 'batch_number' => 'X'];
        app(StockService::class)->stockIn($this->owner, $data);
        $this->assertRefused(fn () => app(StockService::class)->stockIn($this->owner, $data), 'reference');
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_migration_turns_existing_stock_into_one_opening_lot_per_product_and_branch(): void
    {
        $migration = require database_path('migrations/2026_11_08_091033_create_stock_lots_tables.php');
        $migration->down();
        $withBatch = Product::factory()->create(['stock' => 30, 'batch_number' => 'MIG-1', 'expires_on' => '2027-06-01', 'cost_price' => '55.00']);
        $noBatch = Product::factory()->create(['stock' => 4]);
        $empty = Product::factory()->create(['stock' => 0]);
        DB::table('branch_product_stocks')->insert([
            ['branch_id' => $this->hub->id, 'product_id' => $withBatch->id, 'quantity' => 20, 'low_stock_at' => 5, 'created_at' => '2026-10-01 10:00:00', 'updated_at' => now()],
            ['branch_id' => $this->otherBranch->id, 'product_id' => $withBatch->id, 'quantity' => 10, 'low_stock_at' => 5, 'created_at' => '2026-10-02 10:00:00', 'updated_at' => now()],
            ['branch_id' => $this->hub->id, 'product_id' => $noBatch->id, 'quantity' => 4, 'low_stock_at' => 5, 'created_at' => '2026-10-03 10:00:00', 'updated_at' => now()],
            ['branch_id' => $this->hub->id, 'product_id' => $empty->id, 'quantity' => 0, 'low_stock_at' => 5, 'created_at' => '2026-10-03 10:00:00', 'updated_at' => now()],
        ]);

        $migration->up();

        $this->assertDatabaseCount('stock_lots', 3);
        $lot = StockLot::query()->where('branch_id', $this->hub->id)->where('product_id', $withBatch->id)->firstOrFail();
        $this->assertSame(['opening', 'MIG-1', '2027-06-01', 20, 20, '55.00', '2026-10-01'], [$lot->source, $lot->batch_number, $lot->expires_on->toDateString(), $lot->quantity, $lot->received_quantity, $lot->unit_cost, $lot->received_on->toDateString()]);
        $this->assertDatabaseHas('stock_lots', ['branch_id' => $this->otherBranch->id, 'product_id' => $withBatch->id, 'quantity' => 10, 'source' => 'opening']);
        $this->assertNull(StockLot::query()->where('product_id', $noBatch->id)->value('batch_number'));
        $this->assertDatabaseMissing('stock_lots', ['product_id' => $empty->id]);
        $this->assertDatabaseHas('stock_lot_movements', ['lot_id' => $lot->id, 'kind' => 'opening', 'quantity' => 20]);
        $this->assertSame(30, $withBatch->fresh()->stock);
        $this->assertLotsEqualTotals($withBatch);
        $this->assertLotsEqualTotals($noBatch);
    }

    private function product(): Product
    {
        return Product::factory()->create(['stock' => 0, 'low_stock_at' => 1]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function stockIn(Product $product, int $quantity, ?string $batch, ?string $expiresIn, array $extra = [], ?CompanyBranch $branch = null): StockLot
    {
        return app(StockService::class)->stockIn($this->owner, [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'reference' => (string) Str::uuid(),
            'branch_id' => ($branch ?? $this->hub)->id,
            'batch_number' => $batch,
            'expires_on' => $expiresIn !== null ? now()->modify($expiresIn)->toDateString() : null,
            ...$extra,
        ]);
    }

    private function adjust(Product $product, string $kind, int $quantity, string $reasonCode): void
    {
        app(StockService::class)->adjust($this->owner, ['product_id' => $product->id, 'quantity' => $quantity, 'kind' => $kind, 'reason_code' => $reasonCode, 'reason' => $reasonCode, 'reference' => (string) Str::uuid(), 'branch_id' => $this->hub->id]);
    }

    private function paidOrder(Product $product, int $quantity, ?string $courier = null): Order
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid(), 'courier_choice' => $courier]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);

        return $order->fresh();
    }

    private function approvedOrder(Product $product, int $quantity, ?int $topUp = null): Order
    {
        if ($topUp !== null) {
            $this->stockIn($product, $topUp, 'TOPUP', '+200 days');
        }
        $order = $this->paidOrder($product, $quantity);
        app(OrderService::class)->decision($this->owner, $order, 'approved', 'Paid in full');

        return $order->fresh();
    }

    private function dispatchOrder(Order $order, int $quantity): void
    {
        app(OrderService::class)->dispatch($this->owner, $order, [
            'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(),
            'courier' => 'Test courier',
            'tracking' => 'TRK-1',
            'branch_id' => $this->hub->id,
        ]);
    }

    /** @return array{0: Prescription, 1: Product} */
    private function clinicPrescription(int $quantity): array
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'stock' => 0]);
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $this->owner->id]);
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => '2026-11-08', 'diagnosis' => 'Test',
            'lines' => [['product_id' => $product->id, 'dose' => '1', 'unit_price' => '150', 'quantity' => $quantity]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return [Prescription::query()->where('prescriber_id', $this->owner->id)->firstOrFail(), $product];
    }

    private function branchTotal(CompanyBranch $branch, Product $product): int
    {
        return (int) BranchProductStock::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->value('quantity');
    }

    private function assertLotsEqualTotals(Product $product): void
    {
        foreach (BranchProductStock::query()->where('product_id', $product->id)->get() as $stock) {
            $lots = (int) StockLot::query()->where('branch_id', $stock->branch_id)->where('product_id', $product->id)->sum('quantity');
            $this->assertSame((int) $stock->quantity, $lots, 'Lots must add up to the branch total.');
        }
    }

    private function assertRefused(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('This should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
