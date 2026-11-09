<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentQueueFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_queue_shows_order_context_and_filters_claims(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create([
            'name' => 'Queue Dealer',
            'phone' => '01715550001',
            'district' => 'Rangpur',
            'division' => 'Rangpur',
        ]);
        $subDealer = User::factory()->subDealer()->create(['name' => 'Queue Sub']);
        $dealerOrder = Order::create([
            'user_id' => $dealer->id,
            'number' => 'PAY-DEALER-1',
            'status' => 'submitted',
            'total' => '500.00',
            'paid' => '100.00',
            'idempotency_key' => 'pay-dealer-order',
            'payload_hash' => hash('sha256', 'pay-dealer'),
        ]);
        $subOrder = Order::create([
            'user_id' => $subDealer->id,
            'number' => 'PAY-SUB-1',
            'status' => 'approved',
            'total' => '800.00',
            'paid' => '0.00',
            'idempotency_key' => 'pay-sub-order',
            'payload_hash' => hash('sha256', 'pay-sub'),
        ]);
        DB::table('payment_claims')->insert([
            [
                'order_id' => $dealerOrder->id,
                'channel' => 'bKash',
                'reference' => 'BK-QUEUE-1',
                'amount' => '100.00',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'order_id' => $subOrder->id,
                'channel' => 'Bank',
                'reference' => 'BANK-QUEUE-1',
                'amount' => '800.00',
                'status' => 'verified',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/payments?q=BK-QUEUE-1')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.payments_table.rows.data', 1)
                ->where('data.payments_table.rows.data.0.number', 'PAY-DEALER-1')
                ->where('data.payments_table.rows.data.0.name', 'Queue Dealer')
                ->where('data.payments_table.rows.data.0.district', 'Rangpur')
                ->where('data.payments_table.rows.data.0.total', '500.00')
                ->where('data.payments_table.rows.data.0.paid', '100.00')
                ->where('filters.q', 'BK-QUEUE-1'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/payments?buyer_role=sub_dealer&status=verified&channel=Bank')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.payments_table.rows.data', 1)
                ->where('data.payments_table.rows.data.0.number', 'PAY-SUB-1')
                ->where('data.payments_table.rows.data.0.role', 'sub_dealer'));
    }
}
