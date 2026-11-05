<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The ledger cannot be rewritten, money audit records carry the amounts, and
 * one broken wallet cannot hold back everyone else's maturing commissions.
 */
class LedgerSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_journal_rows_cannot_be_edited_or_deleted(): void
    {
        $owner = User::factory()->create(['role' => 'dealer']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $payer = User::factory()->create(['role' => 'finance']);
        $this->payWithdrawal($owner, $reviewer, $payer);
        $this->assertTrue(DB::table('journal_lines')->exists());

        foreach ([
            fn () => DB::table('journal_lines')->update(['debit' => '1.00']),
            fn () => DB::table('journal_lines')->delete(),
            fn () => DB::table('journal_entries')->update(['description' => 'rewritten']),
            fn () => DB::table('journal_entries')->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('A ledger row was changed.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
    }

    public function test_withdrawal_payment_audit_records_the_amount_and_reference(): void
    {
        $owner = User::factory()->create(['role' => 'dealer']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $payer = User::factory()->create(['role' => 'finance']);
        $id = $this->payWithdrawal($owner, $reviewer, $payer);

        $metadata = json_decode((string) DB::table('audit_events')->where('action', 'withdrawal.paid')->where('subject', $id)->value('metadata'), true);

        $this->assertSame('200.00', $metadata['amount']);
        $this->assertSame('PAY-AUDIT-1', $metadata['payment_reference']);
        $this->assertSame($owner->id, $metadata['user_id']);
    }

    public function test_one_drifted_wallet_does_not_block_other_commissions_from_maturing(): void
    {
        CommissionRuleParameter::getRule('commission_cooling_off')?->update(['parameters' => ['cooling_off_days' => 7]]);
        $sponsor = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($sponsor->id, null);
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($buyer->id, $sponsor->id);
        $order = Order::create(['idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'drift'), 'number' => 'ORD-DRIFT-001',
            'user_id' => $buyer->id, 'status' => 'delivered', 'total' => '10000.00', 'paid' => '10000.00']);
        $product = Product::factory()->create(['mrp' => '20000.00']);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1,
            'mrp' => $product->mrp, 'unit_price' => '10000.00', 'total' => '10000.00']);
        app(CommissionService::class)->processOrder($order);
        foreach (CommissionAllocation::where('order_id', $order->id)->where('status', 'maturing')->get() as $allocation) {
            $allocation->update(['metadata' => [...$allocation->metadata, 'maturation_due_at' => now()->subMinute()->toIso8601String()]]);
        }
        // The sponsor's held balance has drifted below what is maturing for them.
        DB::table('wallets')->where('user_id', $sponsor->id)->update(['commission_held' => '0.00', 'held' => '0.00']);
        Log::spy();

        $result = app(CommissionService::class)->releaseMaturedCommissions();

        $this->assertSame(1, $result['released_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame('available', CommissionAllocation::where('order_id', $order->id)->where('user_id', $buyer->id)->value('status'));
        $this->assertSame('maturing', CommissionAllocation::where('order_id', $order->id)->where('user_id', $sponsor->id)->value('status'));
        $this->assertSame(Money::decimal(Money::cents('500.00')), DB::table('wallets')->where('user_id', $buyer->id)->value('available'));
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context): bool => $message === 'commission.maturity_release_skipped'
            && $context['user_id'] === $sponsor->id);
    }

    private function payWithdrawal(User $owner, User $reviewer, User $payer): string
    {
        $id = (string) Str::uuid();
        DB::table('wallets')->insert(['user_id' => $owner->id, 'available' => '0.00', 'held' => '200.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $owner->id, 'amount' => '200.00', 'status' => 'approved', 'channel' => 'Bank',
            'destination' => encrypt('TEST-ACCOUNT-1234'), 'idempotency_key' => (string) Str::uuid(), 'reviewed_by' => $reviewer->id,
            'created_at' => now()->subDay(), 'updated_at' => now()]);
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [
            'payment_reference' => 'PAY-AUDIT-1', 'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent by bank transfer',
            'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $id;
    }
}
