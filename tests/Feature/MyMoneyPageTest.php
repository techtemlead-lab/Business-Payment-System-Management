<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MyMoneyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dealer_gets_everything_the_my_money_page_reads(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/my-money')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertMyMoneyProps($page));
    }

    public function test_a_sub_dealer_gets_everything_the_my_money_page_reads(): void
    {
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        $this->actingAs($subDealer)->onPortal('subdealer')
            ->get('/my-money')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertMyMoneyProps($page));
    }

    public function test_the_partner_home_carries_the_income_cards(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->loadDeferredProps(fn (AssertableInertia $reload) => $reload->has('data.money_cards')));
    }

    public function test_every_my_money_word_is_there_in_both_languages(): void
    {
        $bangla = require lang_path('bn/my_money.php');
        $english = require lang_path('en/my_money.php');

        $this->assertSame(array_keys($bangla), array_keys($english));
        $this->assertSame([], array_keys(array_filter($bangla, fn (string $word): bool => trim($word) === '')));
        $this->assertSame([], array_keys(array_filter($english, fn (string $word): bool => trim($word) === '')));
    }

    public function test_lifetime_withdrawal_history_returns_all_statuses_methods_receipts_and_filters(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $staff = User::factory()->staff('finance')->create();
        $paidId = (string) Str::uuid();
        $officeId = (string) Str::uuid();
        $rejectedId = (string) Str::uuid();

        DB::table('withdrawals')->insert([
            [
                'id' => $paidId,
                'user_id' => $dealer->id,
                'amount' => '1500.00',
                'withheld_amount' => '0.00',
                'net_amount' => '1500.00',
                'status' => 'paid',
                'channel' => 'bKash',
                'destination' => encrypt('01711112222'),
                'idempotency_key' => (string) Str::uuid(),
                'reason' => null,
                'payment_reference' => 'TRX-HIST-1',
                'receipt_path' => 'withdrawal-receipts/test.webp',
                'receipt_mime' => 'image/webp',
                'paid_at' => now()->subDays(5),
                'created_at' => now()->subDays(6),
                'updated_at' => now()->subDays(5),
            ],
            [
                'id' => $officeId,
                'user_id' => $dealer->id,
                'amount' => '800.00',
                'withheld_amount' => '0.00',
                'net_amount' => '800.00',
                'status' => 'requested',
                'channel' => 'Office',
                'destination' => encrypt('office'),
                'idempotency_key' => (string) Str::uuid(),
                'reason' => null,
                'payment_reference' => null,
                'receipt_path' => null,
                'receipt_mime' => null,
                'paid_at' => null,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
            [
                'id' => $rejectedId,
                'user_id' => $dealer->id,
                'amount' => '500.00',
                'withheld_amount' => '0.00',
                'net_amount' => '500.00',
                'status' => 'rejected',
                'channel' => 'Bank',
                'destination' => encrypt('ACCT-99887766'),
                'idempotency_key' => (string) Str::uuid(),
                'reason' => 'Wrong account number',
                'payment_reference' => null,
                'receipt_path' => null,
                'receipt_mime' => null,
                'paid_at' => null,
                'created_at' => now()->subDays(45),
                'updated_at' => now()->subDays(45),
            ],
        ]);

        DB::table('payout_receipts')->insert([
            'number' => 'MN-PR-000101',
            'withdrawal_id' => $paidId,
            'user_id' => $dealer->id,
            'amount' => '1500.00',
            'withheld_amount' => '0.00',
            'net_amount' => '1500.00',
            'method' => 'bkash',
            'reference' => 'TRX-HIST-1',
            'paid_at' => now()->subDays(5),
            'paid_by' => $staff->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/my-money')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.lifetime.withdrawn', '1500.00')
                ->where('withdrawal_history.summary.paid_count', 1)
                ->where('withdrawal_history.summary.pending_count', 1)
                ->where('withdrawal_history.summary.total_count', 3)
                ->has('withdrawal_history.rows.data', 3)
                ->where('withdrawal_history.rows.data.0.id', $officeId)
                ->where('withdrawal_history.rows.data.0.channel', 'Office')
                ->where('withdrawal_history.rows.data.0.can_cancel', true)
                ->where('withdrawal_history.rows.data.1.id', $paidId)
                ->where('withdrawal_history.rows.data.1.receipt_number', 'MN-PR-000101')
                ->where('withdrawal_history.rows.data.1.has_proof', true)
                ->where('withdrawal_history.rows.data.1.destination_masked', '•••••••2222')
                ->where('withdrawal_history.rows.data.2.id', $rejectedId)
                ->where('withdrawal_history.rows.data.2.reason', 'Wrong account number'));

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/my-money?wd_status=paid&wd_channel=bKash')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('withdrawal_history.filters.status', 'paid')
                ->where('withdrawal_history.filters.channel', 'bKash')
                ->has('withdrawal_history.rows.data', 1)
                ->where('withdrawal_history.rows.data.0.id', $paidId));

        $owner = User::factory()->staff('super_admin')->create();
        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/treasury/partners/'.$dealer->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('withdrawal_history.rows.data', 3));
    }

    private function assertMyMoneyProps(AssertableInertia $page): AssertableInertia
    {
        return $page->component('MyMoney')
            ->has('summary.available')
            ->has('summary.waiting')
            ->has('summary.asked')
            ->has('summary.total')
            ->has('summary.lifetime.ordered_mrp')
            ->has('summary.lifetime.cash_given')
            ->has('summary.lifetime.withdrawn')
            ->has('summary.lifetime.spent')
            ->has('summary.lifetime.earned')
            ->has('cards')
            ->has('table.rows.data')
            ->has('table.state.filters')
            ->has('withdrawal_history.summary.total_count')
            ->has('withdrawal_history.rows.data')
            ->has('withdraw.can')
            ->has('withdraw.kyc_block')
            ->has('withdraw.minimum')
            ->has('certificates.years')
            ->has('certificates.rank');
    }
}
