<?php

namespace Tests\Feature;

use App\Models\BusinessConfig;
use App\Models\Dealer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Dealer $dealer): array
    {
        return ['dealer_code' => $dealer->code, 'reference' => 'INV-TEST-001', 'product_name' => 'Company product', 'quantity' => 3, 'unit_mrp' => '375.50', 'amount_paid' => '900.00', 'purchased_on' => now()->toDateString(), 'password' => 'password'];
    }

    public function test_company_records_purchase_and_credits_specific_dealer_once_from_total_mrp(): void
    {
        $dealer = Dealer::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/purchases', [...$this->payload($dealer), 'dealer_minor' => 999999, 'status' => 'cancelled'])->assertRedirect()->assertSessionHas('success');
        $this->post('/purchases', $this->payload($dealer))->assertRedirect();
        $purchase = Purchase::first();
        $this->assertSame(112650, $purchase->total_mrp_minor);
        $this->assertSame(90000, $purchase->amount_paid_minor);
        $this->assertSame(56325, $purchase->calculation['dealer_minor']);
        $this->assertSame(33795, $purchase->calculation['company_minor']);
        $this->assertSame(22530, $purchase->calculation['pool_minor']);
        $this->assertSame(56325, (new LedgerService)->balances($dealer->id)['available_minor']);
        $this->assertNotNull($purchase->rule_version_id);
        $this->get('/rules')->assertInertia(fn (Assert $page) => $page->where('purchaseTotals.company_minor', 33795)->where('purchaseTotals.dealer_minor', 56325)->where('purchaseTotals.pool_minor', 22530)->where('purchaseTotals.unallocated_minor', 13518));
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'purchase.recorded', 'subject_id' => $dealer->id]);
        $this->post('/purchases', [...$this->payload($dealer), 'quantity' => 4])->assertSessionHasErrors('reference');
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_dealer_cannot_record_purchases_or_access_another_dealers_records(): void
    {
        $own = Dealer::factory()->create();
        $own->user->update(['role' => 'dealer']);
        Purchase::factory()->create(['dealer_id' => $own->id, 'reference' => 'OWN-PURCHASE']);
        $other = Purchase::factory()->create(['reference' => 'OTHER-PRIVATE']);
        $this->actingAs($own->user)->get('/purchases')->assertInertia(fn (Assert $page) => $page->has('purchases.data', 1)->where('purchases.data.0.reference', 'OWN-PURCHASE'));
        $this->post('/purchases', $this->payload($own))->assertForbidden();
        $this->post('/purchases/'.$other->id.'/cancel', ['reason' => 'Tampered request', 'password' => 'password'])->assertForbidden();
    }

    public function test_cancellation_preserves_purchase_and_reverses_earnings(): void
    {
        $dealer = Dealer::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/purchases', $this->payload($dealer));
        $purchase = Purchase::first();
        $this->post('/purchases/'.$purchase->id.'/cancel', ['reason' => 'Invoice recorded incorrectly', 'password' => 'password'])->assertRedirect();
        $this->assertSame('cancelled', $purchase->fresh()->status);
        $this->assertSame(112650, $purchase->fresh()->total_mrp_minor);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame(0, (new LedgerService)->balances($dealer->id)['available_minor']);
        $this->assertSame(0, Purchase::allocationTotals()['pool_minor']);
        $this->post('/purchases/'.$purchase->id.'/cancel', ['reason' => 'Repeat correction', 'password' => 'password'])->assertSessionHasErrors('purchase');
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_reserved_earnings_block_cancellation_and_leave_history_intact(): void
    {
        $dealer = Dealer::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/purchases', $this->payload($dealer));
        $purchase = Purchase::first();
        LedgerEntry::create(['dealer_id' => $dealer->id, 'amount_minor' => -100, 'kind' => 'reserved', 'business_reference' => 'test-reserve', 'reason' => 'Test fixture', 'created_at' => now()]);
        $this->post('/purchases/'.$purchase->id.'/cancel', ['reason' => 'Correct invoice', 'password' => 'password'])->assertSessionHasErrors('entry');
        $this->assertSame('recorded', $purchase->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_invalid_input_and_missing_password_do_not_create_earnings(): void
    {
        $dealer = Dealer::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = $this->payload($dealer);
        unset($payload['password']);
        $this->post('/purchases', $payload)->assertSessionHasErrors('password');
        $this->post('/purchases', [...$this->payload($dealer), 'quantity' => 0, 'purchased_on' => now()->addDay()->toDateString()])->assertSessionHasErrors(['quantity', 'purchased_on']);
        $this->post('/purchases', [...$this->payload($dealer), 'unit_mrp' => '-2'])->assertSessionHasErrors('unit_mrp');
        $this->post('/purchases', [...$this->payload($dealer), 'quantity' => 100000, 'unit_mrp' => '999999999'])->assertSessionHasErrors('unit_mrp');
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_rank_mode_records_purchase_without_inventing_rank_earnings(): void
    {
        BusinessConfig::find(1)->update(['mode' => 'RANK_BASED']);
        $dealer = Dealer::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/purchases', $this->payload($dealer))->assertRedirect();
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertNull(Purchase::first()->ledger_entry_id);
        $this->assertTrue(Purchase::first()->calculation['preview_only']);
    }
}
