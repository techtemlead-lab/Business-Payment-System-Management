<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WithdrawalRequestTest extends TestCase
{
    use RefreshDatabase;

    private function fundedDealer(): Dealer
    {
        $dealer = Dealer::factory()->create();
        $dealer->user->update(['role' => 'dealer']);
        LedgerEntry::create(['dealer_id' => $dealer->id, 'amount_minor' => 10000, 'kind' => 'earned', 'business_reference' => 'test-earning-'.$dealer->id, 'reason' => 'Test fixture only', 'created_at' => now()]);

        return $dealer;
    }

    private function payload(): array
    {
        return ['amount' => '60.00', 'note' => 'Please review my request', 'idempotency_key' => '9e16d00e-984d-4c51-95e6-ce91230d871e'];
    }

    public function test_dealer_requests_reserve_real_funds_and_retries_do_not_duplicate_them(): void
    {
        $dealer = $this->fundedDealer();
        $payload = [...$this->payload(), 'dealer_id' => 999, 'status' => 'approved', 'balance_minor' => 999999];
        $this->actingAs($dealer->user)->post('/withdrawal-requests', $payload)->assertRedirect()->assertSessionHas('success');
        $this->post('/withdrawal-requests', $payload)->assertRedirect();
        $this->assertDatabaseHas('withdrawal_requests', ['dealer_id' => $dealer->id, 'status' => 'pending', 'amount_minor' => 6000]);
        $this->assertDatabaseCount('withdrawal_requests', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount('payouts', 0);
        $this->assertSame(4000, (new LedgerService)->balances($dealer->id)['available_minor']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/payouts')->assertInertia(fn (Assert $page) => $page->has('withdrawals.data', 1)->where('withdrawals.data.0.dealer_id', $dealer->id));
    }

    public function test_insufficient_funds_and_conflicting_retry_are_rejected(): void
    {
        $dealer = $this->fundedDealer();
        $this->actingAs($dealer->user)->post('/withdrawal-requests', [...$this->payload(), 'amount' => '100.01'])->assertSessionHasErrors('amount');
        $this->post('/withdrawal-requests', $this->payload())->assertRedirect();
        $this->post('/withdrawal-requests', [...$this->payload(), 'amount' => '10.00'])->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('withdrawal_requests', 1);
    }

    public function test_approval_requires_company_password_and_never_marks_money_paid(): void
    {
        $dealer = $this->fundedDealer();
        $this->actingAs($dealer->user)->post('/withdrawal-requests', $this->payload());
        $id = WithdrawalRequest::first()->id;
        $this->patch('/withdrawal-requests/'.$id, ['status' => 'approved', 'reason' => 'Self approval attempt', 'password' => 'password'])->assertSessionHasErrors('status');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch('/withdrawal-requests/'.$id, ['status' => 'approved', 'reason' => 'Reviewed request'])->assertSessionHasErrors('password');
        $this->patch('/withdrawal-requests/'.$id, ['status' => 'approved', 'reason' => 'Reviewed request', 'password' => 'password'])->assertRedirect();
        $this->assertDatabaseHas('withdrawal_requests', ['id' => $id, 'status' => 'approved']);
        $this->assertSame(6000, (new LedgerService)->balances($dealer->id)['reserved_minor']);
        $this->assertSame(0, (new LedgerService)->balances($dealer->id)['paid_minor']);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_unconfigured_payment_release_is_blocked_and_approved_requests_can_be_declined(): void
    {
        $dealer = $this->fundedDealer();
        $this->actingAs($dealer->user)->post('/withdrawal-requests', $this->payload());
        $id = WithdrawalRequest::first()->id;
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch('/withdrawal-requests/'.$id, ['status' => 'approved', 'reason' => 'Reviewed request', 'password' => 'password'])->assertRedirect();
        $this->post('/withdrawal-requests/'.$id.'/release', ['password' => 'password'])->assertSessionHasErrors('provider');
        $this->assertSame(0, (new LedgerService)->balances($dealer->id)['paid_minor']);
        $this->assertDatabaseCount('payouts', 0);
        $this->patch('/withdrawal-requests/'.$id, ['status' => 'declined', 'reason' => 'Provider decision postponed', 'password' => 'password'])->assertRedirect();
        $this->assertSame(10000, (new LedgerService)->balances($dealer->id)['available_minor']);
    }

    public function test_cancellation_releases_once_and_other_dealers_cannot_view_or_mutate(): void
    {
        $dealer = $this->fundedDealer();
        $this->actingAs($dealer->user)->post('/withdrawal-requests', $this->payload());
        $id = WithdrawalRequest::first()->id;
        $other = $this->fundedDealer();
        $this->actingAs($other->user)->get('/payouts')->assertInertia(fn (Assert $page) => $page->has('withdrawals.data', 0));
        $this->patch('/withdrawal-requests/'.$id, ['status' => 'cancelled', 'reason' => 'Not my request', 'password' => 'password'])->assertNotFound();
        $this->actingAs($dealer->user)->patch('/withdrawal-requests/'.$id, ['status' => 'cancelled', 'reason' => 'No longer needed', 'password' => 'password'])->assertRedirect();
        $this->patch('/withdrawal-requests/'.$id, ['status' => 'cancelled', 'reason' => 'Repeat cancellation', 'password' => 'password'])->assertSessionHasErrors('withdrawal');
        $this->assertSame(10000, (new LedgerService)->balances($dealer->id)['available_minor']);
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal.cancelled']);
        $this->assertSame(1, LedgerEntry::where('kind', 'released')->count());
    }

    public function test_company_cannot_submit_a_dealer_request_and_invalid_amounts_leave_funds_untouched(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/withdrawal-requests', $this->payload())->assertForbidden();
        $dealer = $this->fundedDealer();
        foreach (['-1', 'bad', '0', '1.999'] as $amount) {
            $this->actingAs($dealer->user)->post('/withdrawal-requests', [...$this->payload(), 'amount' => $amount])->assertSessionHasErrors('amount');
        }
        $this->assertDatabaseCount('withdrawal_requests', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
    }
}
