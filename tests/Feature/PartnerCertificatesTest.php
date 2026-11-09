<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerCertificates;
use App\Models\CommissionAllocation;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerCertificatesTest extends TestCase
{
    use RefreshDatabase;

    private function allocation(User $user, string $category, string $amount, string $status, $createdAt): void
    {
        $order = Order::create([
            'number' => 'ORD-CERT-'.Str::random(6), 'user_id' => $user->id, 'status' => 'delivered', 'channel' => 'dealer', 'currency' => 'BDT',
            'subtotal' => '1000.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => '1000.00', 'paid' => '1000.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random()),
        ]);
        DB::table('journal_entries')->insert(['id' => (string) Str::uuid(), 'event_key' => 'commissions.order.'.$order->id, 'description' => 'Commission', 'created_at' => $createdAt]);
        CommissionAllocation::query()->create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'category' => $category,
            'rate_percentage' => '10.00',
            'cash_basis' => '1000.00',
            'amount' => $amount,
            'original_amount' => $amount,
            'status' => $status,
            'metadata' => [],
        ])->forceFill(['created_at' => $createdAt])->save();
    }

    public function test_income_certificate_counts_only_kept_commission_in_that_year(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->allocation($dealer, 'direct_referral', '300.00', 'available', now()->setDate(2026, 3, 10));
        $this->allocation($dealer, 'direct_referral', '200.00', 'maturing', now()->setDate(2026, 6, 1));
        $this->allocation($dealer, 'own_dealer_cashback', '50.00', 'held', now()->setDate(2026, 7, 1));
        $this->allocation($dealer, 'direct_referral', '999.00', 'available', now()->setDate(2025, 12, 31));
        DB::table('withdrawals')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $dealer->id, 'amount' => '400.00', 'withheld_amount' => '20.00', 'net_amount' => '380.00',
            'status' => 'paid', 'channel' => 'bKash', 'destination' => encrypt('01700000000'), 'idempotency_key' => (string) Str::uuid(),
            'paid_at' => now()->setDate(2026, 8, 1), 'created_at' => now()->setDate(2026, 8, 1), 'updated_at' => now(),
        ]);

        $income = app(PartnerCertificates::class)->income($dealer, 2026);

        $this->assertSame('500.00', $income['total']);
        $this->assertSame(['direct_referral'], array_column($income['lines'], 'category'));
        $this->assertSame('380.00', $income['withdrawn_net']);
        $this->assertSame('20.00', $income['tax_cut']);
    }

    public function test_partners_download_their_certificates_and_rank_needs_a_rank(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'dealer_code' => 'MNHC-CERT']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/income-certificate/pdf?year='.now('Asia/Dhaka')->format('Y'))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($dealer)->onPortal('dealer')->get('/rank-certificate/pdf')->assertNotFound();

        DealerRank::create(['user_id' => $dealer->id, 'current_rank' => 'mo', 'achieved_at' => now()->subDays(10)]);
        $this->actingAs($dealer)->onPortal('dealer')->get('/rank-certificate/pdf')
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($dealer)->onPortal('dealer')->get('/income-certificate/pdf?year=1999')->assertSessionHasErrors('year');
    }
}
