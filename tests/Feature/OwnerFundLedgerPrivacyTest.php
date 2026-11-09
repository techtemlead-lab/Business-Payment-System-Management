<?php

namespace Tests\Feature;

use App\Domain\Finance\ReportData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OwnerFundLedgerPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const OwnerSpendText = 'Owner benefits fund spend: Family trip';

    private const OrdinarySpendText = 'Marketing spend: Posters';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $owner = User::factory()->staff()->create();
        $this->spend($owner, 'benefits', self::OwnerSpendText);
        $this->spend($owner, 'marketing', self::OrdinarySpendText);
    }

    private function spend(User $owner, string $fund, string $description): void
    {
        $id = DB::table('reserve_disbursements')->insertGetId([
            'program' => 'company_spend', 'fund_key' => $fund, 'amount' => '500.00', 'status' => 'paid', 'reason' => 'Test spend',
            'payee_name' => 'Test payee', 'request_key' => (string) Str::uuid(), 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_entries')->insert(['id' => (string) Str::uuid(), 'event_key' => 'fund.spend.'.$id, 'description' => $description, 'actor_id' => $owner->id, 'created_at' => now()]);
    }

    public function test_finance_staff_do_not_see_owner_fund_spends_on_the_ledger_page(): void
    {
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get('/ledger')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.entries.data', fn ($rows): bool => collect($rows)->pluck('description')->all() === [self::OrdinarySpendText]));
    }

    public function test_finance_staff_do_not_get_owner_fund_spends_in_the_ledger_report(): void
    {
        $today = now('Asia/Dhaka')->toDateString();
        DB::table('journal_lines')->insert(DB::table('journal_entries')->pluck('id')->map(fn (string $entry): array => ['entry_id' => $entry, 'account' => 'company_fund_expense', 'debit' => '500.00', 'credit' => '0.00'])->all());

        $rows = app(ReportData::class)->query(User::factory()->staff('finance')->create(), 'ledger', $today, $today)['query']->get();

        $this->assertSame([self::OrdinarySpendText], $rows->pluck('description')->all());
    }

    public function test_the_owner_still_sees_them_on_the_owner_panel(): void
    {
        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/ledger')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.entries.data', fn ($rows): bool => collect($rows)->pluck('description')->contains(self::OwnerSpendText)));
    }
}
