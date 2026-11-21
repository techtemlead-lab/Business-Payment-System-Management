<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderDelivery;
use App\Domain\Orders\OrderInvoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The invoice says how the goods reached the partner (office pickup or courier) and which payment
 * method paid for them.
 */
class InvoiceDeliveryAndPaymentLinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_with_no_shipment_says_nothing_about_delivery(): void
    {
        [$order, $dealer] = $this->orderWithDealer();

        $this->assertSame('none', app(OrderDelivery::class)->for($order)['kind']);
        $html = $this->html($order, $dealer);
        $this->assertStringNotContainsString('Taken from office', $html);
        $this->assertStringNotContainsString('Courier :', $html);
    }

    public function test_goods_taken_from_the_office_say_so_in_both_languages(): void
    {
        [$order, $dealer] = $this->orderWithDealer();
        $this->shipment($order, $dealer, ['courier' => 'Office pickup', 'tracking' => '']);

        $this->assertSame('office', app(OrderDelivery::class)->for($order)['kind']);
        $this->assertStringContainsString('Delivery : Taken from office', $this->html($order, $dealer));

        App::setLocale('bn');
        $this->assertStringContainsString('অফিস থেকে নিয়েছেন', $this->html($order, $dealer));
    }

    public function test_a_courier_shipment_shows_the_courier_name_and_tracking(): void
    {
        [$order, $dealer] = $this->orderWithDealer();
        $this->shipment($order, $dealer, ['courier' => 'Sundarban Courier', 'tracking' => 'SC-4471']);

        $html = $this->html($order, $dealer);

        $this->assertStringContainsString('Courier : Sundarban Courier', $html);
        $this->assertStringContainsString('Tracking : SC-4471', $html);
        $this->assertStringNotContainsString('Taken from office', $html);
    }

    public function test_a_booked_courier_shows_its_service_name_and_consignment_number(): void
    {
        [$order, $dealer] = $this->orderWithDealer();
        $this->shipment($order, $dealer, ['courier' => 'Pathao', 'tracking' => '', 'courier_provider' => 'pathao', 'courier_consignment_id' => 'DL123456']);

        $html = $this->html($order, $dealer);

        $this->assertStringContainsString('Courier : Pathao', $html);
        $this->assertStringContainsString('Tracking : DL123456', $html);
    }

    public function test_an_order_with_no_payment_yet_says_so(): void
    {
        [$order, $dealer] = $this->orderWithDealer();

        $html = $this->html($order, $dealer);

        $this->assertStringContainsString('Payment method', $html);
        $this->assertStringContainsString('No payment received yet', $html);
    }

    public function test_each_payment_shows_its_method_amount_and_reference(): void
    {
        [$order, $dealer] = $this->orderWithDealer();
        foreach ([['bkash', 'BK-9001', '300.00'], ['office', 'OFF-7', '510.00']] as [$channel, $reference, $amount]) {
            $claim = DB::table('payment_claims')->insertGetId([
                'order_id' => $order->id, 'channel' => $channel, 'reference' => $reference, 'amount' => $amount,
                'status' => 'verified', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('receipts')->insert([
                'order_id' => $order->id, 'claim_id' => $claim, 'channel' => $channel, 'reference' => $reference,
                'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $html = $this->html($order, $dealer);

        $this->assertStringNotContainsString('No payment received yet', $html);
        $this->assertStringContainsString('bKash', $html);
        $this->assertStringContainsString('BK-9001', $html);
        $this->assertStringContainsString('300.00', $html);
        $this->assertStringContainsString('By Office (cash at office)', $html);
        $this->assertStringContainsString('510.00', $html);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function shipment(Order $order, User $actor, array $extra): void
    {
        DB::table('shipments')->insert([
            'order_id' => $order->id,
            'status' => 'dispatched',
            'lines' => '[]',
            'actor_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
            ...$extra,
        ]);
    }

    private function html(Order $order, User $viewer): string
    {
        return (string) app(OrderInvoice::class)->printHtml($viewer, $order)->getContent();
    }

    /**
     * @return array{0: Order, 1: User}
     */
    private function orderWithDealer(): array
    {
        $dealer = User::factory()->dealer()->create();
        $order = Order::create([
            'user_id' => $dealer->id,
            'number' => 'INV-DLV-'.Str::random(6),
            'status' => 'approved',
            'total' => '810.00',
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::uuid()),
        ]);
        $product = Product::factory()->create(['mrp' => '450.00', 'dealer_price' => '270.00']);
        $order->lines()->create([
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => 3, 'mrp' => $product->mrp, 'unit_price' => '270.00', 'total' => '810.00',
        ]);

        return [$order, $dealer];
    }
}
