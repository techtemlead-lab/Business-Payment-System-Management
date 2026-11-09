<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderInvoice;
use App\Models\Order;
use App\Models\PaymentClaim;
use App\Models\Receipt;
use App\Models\User;
use App\Support\PaymentChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentChannelWordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_channel_is_named_in_the_readers_language(): void
    {
        $this->assertSame('ব্যাংক', PaymentChannel::word('Bank', 'bn'));
        $this->assertSame('Bank', PaymentChannel::word('bank', 'en'));
        $this->assertSame('বিকাশ', PaymentChannel::word('bKash', 'bn'));
        $this->assertSame('bKash', PaymentChannel::word('bKash', 'en'));
        $this->assertSame('Office cash', PaymentChannel::word('Office cash', 'bn'));
    }

    public function test_a_bangla_invoice_names_the_bank_in_bangla(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $order = Order::create(['user_id' => $dealer->id, 'number' => 'QA-CHANNEL-1', 'total' => '100.00', 'paid' => '100.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'QA-CHANNEL-1')]);
        $claim = PaymentClaim::create(['order_id' => $order->id, 'channel' => 'Bank', 'reference' => 'TXN-1', 'amount' => '100.00', 'status' => 'verified', 'user_id' => $dealer->id]);
        Receipt::create(['order_id' => $order->id, 'claim_id' => $claim->id, 'channel' => 'Bank', 'reference' => 'TXN-1', 'amount' => '100.00']);

        app()->setLocale('bn');

        $this->assertSame('ব্যাংক', app(OrderInvoice::class)->payload($order->fresh(), 'copy')['payments'][0]['channel']);
    }
}
