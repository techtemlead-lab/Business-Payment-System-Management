<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerWalletStatement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PartnerWalletStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_statement_computes_running_balance_newest_first(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->postWalletLine($dealer->id, '100.00', '0.00', 'Opening credit', now()->subDays(2));
        $this->postWalletLine($dealer->id, '50.00', '0.00', 'Second credit', now()->subDay());
        $this->postWalletLine($dealer->id, '0.00', '30.00', 'Withdrawal', now());

        $statement = app(PartnerWalletStatement::class)->for($dealer);

        $this->assertSame('0.00', $statement['opening_balance']);
        $this->assertSame('120.00', $statement['closing_balance']);
        $this->assertSame('120.00', $statement['period_net']);
        $this->assertCount(3, $statement['lines']);
        $this->assertSame('Withdrawal', $statement['lines'][0]['description']);
        $this->assertSame('120.00', $statement['lines'][0]['balance']);
        $this->assertSame('30.00', $statement['lines'][0]['debit']);
        $this->assertSame('150.00', $statement['lines'][1]['balance']);
        $this->assertSame('100.00', $statement['lines'][2]['balance']);
    }

    public function test_statement_respects_asia_dhaka_date_bounds_and_opening_balance(): void
    {
        config(['madina.business_timezone' => 'Asia/Dhaka']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        // Asia/Dhaka day 2026-03-10 = [2026-03-09 18:00 UTC, 2026-03-10 18:00 UTC).
        $this->postWalletLine($dealer->id, '80.00', '0.00', 'Before window', '2026-03-09 17:00:00');
        $this->postWalletLine($dealer->id, '20.00', '0.00', 'Inside window', '2026-03-09 18:30:00');
        $this->postWalletLine($dealer->id, '0.00', '5.00', 'After window', '2026-03-10 18:00:00');

        $statement = app(PartnerWalletStatement::class)->for($dealer, '2026-03-10', '2026-03-10');

        $this->assertSame('80.00', $statement['opening_balance']);
        $this->assertSame('100.00', $statement['closing_balance']);
        $this->assertSame('20.00', $statement['period_net']);
        $this->assertCount(1, $statement['lines']);
        $this->assertSame('Inside window', $statement['lines'][0]['description']);
        $this->assertSame('100.00', $statement['lines'][0]['balance']);
    }

    public function test_earnings_page_exposes_wallet_statement_only_for_partner(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->postWalletLine($dealer->id, '25.00', '0.00', 'Partner credit', now());
        $this->postWalletLine($other->id, '999.00', '0.00', 'Other credit', now());

        $this->actingAs($dealer)->onPortal('dealer')->get('/earnings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('data.wallet_statement.closing_balance', '25.00')
                ->where('data.wallet_statement.lines.0.description', 'Partner credit')
                ->has('data.wallet_statement.lines', 1));
    }

    public function test_statement_ignores_non_wallet_liability_accounts(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $entry = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $entry,
            'event_key' => 'test.receivable.'.$entry,
            'description' => 'Receivable only',
            'created_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            'entry_id' => $entry,
            'user_id' => $dealer->id,
            'account' => 'opening_receivables',
            'debit' => '40.00',
            'credit' => '0.00',
        ]);
        $this->postWalletLine($dealer->id, '15.00', '0.00', 'Held credit', now(), 'commission_held_liability');

        $statement = app(PartnerWalletStatement::class)->for($dealer);

        $this->assertSame('15.00', $statement['closing_balance']);
        $this->assertCount(1, $statement['lines']);
        $this->assertSame('commission_held_liability', $statement['lines'][0]['account']);
    }

    private function postWalletLine(
        int $userId,
        string $credit,
        string $debit,
        string $description,
        mixed $createdAt,
        string $account = 'wallet_liability',
    ): void {
        $entry = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $entry,
            'event_key' => 'test.wallet.'.$entry,
            'description' => $description,
            'created_at' => $createdAt,
        ]);
        DB::table('journal_lines')->insert([
            'entry_id' => $entry,
            'user_id' => $userId,
            'account' => $account,
            'debit' => Money::decimal(Money::cents($debit)),
            'credit' => Money::decimal(Money::cents($credit)),
        ]);
    }
}
