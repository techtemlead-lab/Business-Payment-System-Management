<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WithdrawalHistoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdrawal_history_shows_payout_context_and_filters_requests(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create([
            'name' => 'History Dealer',
            'phone' => '01715550021',
            'district' => 'Bogura',
            'division' => 'Rajshahi',
        ]);
        $subDealer = User::factory()->subDealer()->create([
            'name' => 'History Sub',
            'phone' => '01815550022',
        ]);
        $this->insertWithdrawal($dealer, [
            'amount' => '200.00',
            'withheld_amount' => '10.00',
            'net_amount' => '190.00',
            'channel' => 'bKash',
            'destination' => encrypt('01711112222'),
            'status' => 'requested',
            'payment_reference' => 'WD-DEALER-1',
            'reason' => 'Waiting for review',
            'created_at' => now(),
        ]);
        $this->insertWithdrawal($subDealer, [
            'amount' => '400.00',
            'withheld_amount' => '20.00',
            'net_amount' => '380.00',
            'channel' => 'Bank',
            'destination' => encrypt('BANK-ACCOUNT-8899'),
            'status' => 'paid',
            'payment_reference' => 'WD-SUB-1',
            'paid_at' => now()->subYear(),
            'created_at' => now()->subYear(),
        ]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/withdrawals?q=WD-DEALER-1')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.withdrawals.data', 1)
                ->where('data.withdrawals.data.0.name', 'History Dealer')
                ->where('data.withdrawals.data.0.phone', '01715550021')
                ->where('data.withdrawals.data.0.district', 'Bogura')
                ->where('data.withdrawals.data.0.division', 'Rajshahi')
                ->where('data.withdrawals.data.0.net_amount', '190.00')
                ->where('data.withdrawals.data.0.withheld_amount', '10.00')
                ->where('data.withdrawals.data.0.destination_masked', '•••••••2222')
                ->missing('data.withdrawals.data.0.destination')
                ->where('filters.q', 'WD-DEALER-1'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/withdrawals?buyer_role=sub_dealer&status=paid&channel=Bank')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.withdrawals.data', 1)
                ->where('data.withdrawals.data.0.payment_reference', 'WD-SUB-1')
                ->where('data.withdrawals.data.0.role', 'sub_dealer'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/withdrawals?period=year')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.withdrawals.data', 1)
                ->where('data.withdrawals.data.0.payment_reference', 'WD-DEALER-1'));

        $this->actingAs($dealer)->onPortal('dealer')->get('/withdrawals')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.withdrawals.data', 1)
                ->where('data.withdrawals.data.0.payment_reference', 'WD-DEALER-1'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertWithdrawal(User $user, array $attributes): void
    {
        DB::table('withdrawals')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }
}
