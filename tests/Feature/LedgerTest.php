<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Dealer;
use App\Models\LedgerEntry;
use App\Models\RuleVersion;
use App\Services\LedgerService;
use App\Services\UnconfiguredPayoutProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function earning(Dealer $d): LedgerEntry
    {
        return LedgerEntry::create(['dealer_id' => $d->id, 'amount_minor' => 10000, 'kind' => 'earned', 'business_reference' => 'test-earning-'.$d->id, 'reason' => 'Test fixture only', 'created_at' => now()]);
    }

    public function test_balances_are_derived_from_server_ledger(): void
    {
        $d = Dealer::factory()->create();
        $this->earning($d);
        LedgerEntry::create(['dealer_id' => $d->id, 'amount_minor' => -3000, 'kind' => 'reserved', 'business_reference' => 'reserve-1', 'reason' => 'Test fixture only', 'created_at' => now()]);
        $this->assertSame(['earned_minor' => 10000, 'reserved_minor' => 3000, 'paid_minor' => 0, 'available_minor' => 7000], (new LedgerService)->balances($d->id));
    }

    public function test_posted_ledger_entry_cannot_be_updated(): void
    {
        $entry = $this->earning(Dealer::factory()->create());
        $this->expectException(\LogicException::class);
        $entry->update(['amount_minor' => 99999]);
    }

    public function test_posted_ledger_entry_cannot_be_deleted(): void
    {
        $entry = $this->earning(Dealer::factory()->create());
        $this->expectException(\LogicException::class);
        $entry->delete();
    }

    public function test_reversal_preserves_original_and_is_idempotent(): void
    {
        $d = Dealer::factory()->create();
        $entry = $this->earning($d);
        $s = new LedgerService;
        $a = $s->reverse($entry->id, 'reverse-1', 'Correction reviewed');
        $b = $s->reverse($entry->id, 'reverse-1', 'Correction reviewed');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(10000, $entry->fresh()->amount_minor);
        $this->assertSame(0, $s->balances($d->id)['available_minor']);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_reserved_funds_prevent_reversal(): void
    {
        $d = Dealer::factory()->create();
        $entry = $this->earning($d);
        LedgerEntry::create(['dealer_id' => $d->id, 'amount_minor' => -1, 'kind' => 'reserved', 'business_reference' => 'reserve-1', 'reason' => 'Test fixture', 'created_at' => now()]);
        $this->expectException(ValidationException::class);
        (new LedgerService)->reverse($entry->id, 'reverse-1', 'Correction reviewed');
    }

    public function test_unapproved_workflow_cannot_post_commissions(): void
    {
        $this->expectException(ValidationException::class);
        (new LedgerService)->postCommission();
    }

    public function test_missing_provider_blocks_payout_without_reserving_money(): void
    {
        $d = Dealer::factory()->create();
        $this->earning($d);
        $this->actingAs($d->user)->post('/payouts', ['amount' => '100.00', 'idempotency_key' => '9e16d00e-984d-4c51-95e6-ce91230d871e', 'status' => 'succeeded', 'balance_minor' => 999999])->assertSessionHasErrors('payout');
        $this->assertDatabaseCount('payouts', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(10000, (new LedgerService)->balances($d->id)['available_minor']);
    }

    public function test_unverified_webhooks_and_ambiguous_results_never_become_success(): void
    {
        $p = new UnconfiguredPayoutProvider;
        $this->assertFalse($p->verifyWebhook('{"status":"succeeded"}', 'forged'));
        $this->assertSame(['status' => 'needs_reconciliation'], $p->reconcile('unknown-reference'));
        $this->assertFalse($p->available());
    }

    public function test_rule_versions_are_immutable(): void
    {
        $r = RuleVersion::create(['name' => 'Test reference', 'status' => 'reference', 'definition' => [], 'reason' => 'Test fixture']);
        $this->expectException(\LogicException::class);
        $r->update(['definition' => ['arbitrary' => 1]]);
    }

    public function test_audit_records_cannot_be_deleted(): void
    {
        $a = AuditEvent::record('test.event');
        $this->expectException(\LogicException::class);
        $a->delete();
    }
}
