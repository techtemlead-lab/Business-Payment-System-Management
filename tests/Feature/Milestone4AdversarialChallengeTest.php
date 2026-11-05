<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\E2E\E2ETestCase;

class Milestone4AdversarialChallengeTest extends E2ETestCase
{
    private function createSubmittedOrder(User $dealer, ?Product $product = null, int $quantity = 2): Order
    {
        $product ??= $this->createProduct(['mrp' => '400.00', 'dealer_price' => '250.00', 'stock' => 50]);

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return Order::findOrFail($response->json('data.id'));
    }

    /**
     * Test 1: In WithdrawalDetail.tsx:
     * Check how WithdrawalDetail handles all 5 statuses:
     * - requested, processing, approved (inFlight = true)
     * - paid, rejected (inFlight = false, terminal)
     */
    public function test_withdrawal_detail_statuses_and_in_flight_states(): void
    {
        $user = $this->createDealer();

        $statuses = ['requested', 'processing', 'approved', 'paid', 'rejected'];

        foreach ($statuses as $st) {
            $withdrawalId = (string) Str::uuid();
            DB::table('withdrawals')->insert([
                'id' => $withdrawalId,
                'user_id' => $user->id,
                'amount' => 1000.00,
                'status' => $st,
                'channel' => 'bKash',
                'destination' => encrypt('01711000000'),
                'idempotency_key' => 'idem_'.$st.'_'.$withdrawalId,
                'reason' => $st === 'rejected' ? 'Invalid KYC' : null,
                'payment_reference' => $st === 'paid' ? 'TRX-12345' : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response = $this->actingAs($user)->onPortal('dealer')->get('/withdrawals/'.$withdrawalId);
            $response->assertOk();
            $response->assertInertia(fn (Assert $page) => $page
                ->component('WithdrawalDetail')
                ->where('withdrawal.status', $st)
                ->where('withdrawal.id', $withdrawalId)
            );
        }
    }

    /**
     * Test 2: WithdrawalDetail partial polling with usePoll(5000, { only: ['withdrawal'] })
     */
    public function test_withdrawal_detail_partial_reload_only_withdrawal(): void
    {
        $user = $this->createDealer();
        $withdrawalId = (string) Str::uuid();
        DB::table('withdrawals')->insert([
            'id' => $withdrawalId,
            'user_id' => $user->id,
            'amount' => 1500.00,
            'status' => 'requested',
            'channel' => 'Nagad',
            'destination' => encrypt('01811000000'),
            'idempotency_key' => 'idem_'.$withdrawalId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->onPortal('dealer')
            ->get('/withdrawals/'.$withdrawalId)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WithdrawalDetail')
                ->has('withdrawal')
                ->reloadOnly('withdrawal', fn (Assert $reloaded) => $reloaded
                    ->has('withdrawal')
                    ->where('withdrawal.status', 'requested')
                    ->missing('events')
                    ->missing('paymentAccounts')
                )
            );
    }

    /**
     * Test 3: OrderDetail partial reload with only: ['order', 'claims', 'shipments']
     */
    public function test_order_detail_partial_reload(): void
    {
        $user = $this->createDealer();
        $order = $this->createSubmittedOrder($user);

        $this->actingAs($user)->onPortal('dealer')
            ->get('/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('OrderDetail')
                ->has('order')
                ->reloadOnly(['order', 'claims', 'shipments'], fn (Assert $reloaded) => $reloaded
                    ->has('order')
                    ->has('claims')
                    ->has('shipments')
                )
            );
    }

    /**
     * Test 4: Portal partial reload with { only: ['wallet', 'navAttention'] }
     * Demonstrating failure of worker's parameter: 'wallet' is missing from reloaded props.
     */
    public function test_portal_partial_reload_worker_parameters_fails_to_return_wallet(): void
    {
        $user = $this->createDealer();
        DB::table('wallets')->updateOrInsert(
            ['user_id' => $user->id],
            ['available' => 5000.00, 'held' => 0, 'commission_held' => 0]
        );

        $this->actingAs($user)->onPortal('dealer')
            ->get('/withdrawals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->reload(
                    callback: function (Assert $reloaded) {
                        $props = $reloaded->toArray()['props'];
                        // navAttention is returned because it matches shared prop
                        $this->assertArrayHasKey('navAttention', $props);
                        // wallet is NOT returned because it is nested under data.wallet, not a top-level prop
                        $this->assertArrayNotHasKey('wallet', $props, 'Worker param "wallet" is dropped by server PropsResolver');
                        // data is NOT returned because "data" was not in only: [...]
                        $this->assertArrayNotHasKey('data', $props, 'Worker param omitted "data", so data is dropped');
                    },
                    only: ['wallet', 'navAttention']
                )
            );
    }

    /**
     * Test 5: Portal partial reload with corrected parameters:
     * only: ['data.wallet', 'data.withdrawal_balances', 'navAttention']
     */
    public function test_portal_partial_reload_with_corrected_dot_notation(): void
    {
        $user = $this->createDealer();
        DB::table('wallets')->updateOrInsert(
            ['user_id' => $user->id],
            ['available' => 5000.00, 'held' => 0, 'commission_held' => 0]
        );

        $this->actingAs($user)->onPortal('dealer')
            ->get('/withdrawals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->reload(
                    callback: function (Assert $reloaded) {
                        $props = $reloaded->toArray()['props'];
                        $this->assertArrayHasKey('navAttention', $props);
                        $this->assertArrayHasKey('data', $props);
                        $this->assertArrayHasKey('wallet', $props['data']);
                        $this->assertArrayHasKey('withdrawal_balances', $props['data']);
                        $this->assertEquals('5000.00', $props['data']['wallet']['available']);
                        $this->assertEquals('5000.00', $props['data']['withdrawal_balances']['available']);
                    },
                    only: ['data.wallet', 'data.withdrawal_balances', 'navAttention']
                )
            );
    }
}
