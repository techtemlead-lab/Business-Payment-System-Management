<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Owner's question: money flows into the company funds, so how does it come out? One person
 * records a spend with its head and details, another approves it, and the books stay balanced.
 */
class CompanyFundSpendingTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
        $this->order = $this->postedOrder();
    }

    public function test_a_spend_reduces_the_balance_and_posts_balanced_journals(): void
    {
        [$maker, $checker] = $this->financeStaff();
        $this->fundRow('company_profit_fund', '1000.00');
        $before = $this->balance('company_profit');

        $id = $this->spend($maker, ['amount' => '250.00', 'receipt' => UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf')])->assertOk()->json('data.id');

        $this->assertSame($before, $this->balance('company_profit'), 'A recorded spend leaves the balance alone until it is approved.');
        $this->assertSame($before - 25000, $this->available('company_profit'), 'A waiting spend cannot be promised twice.');
        $this->assertNotNull(DB::table('reserve_disbursements')->where('id', $id)->value('receipt_path'));

        $this->review($checker, $id, 'approved')->assertOk();

        $this->assertSame($before - 25000, $this->balance('company_profit'));
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'paid', 'approved_by' => $checker->id]);
        $this->assertSame('250.00', (string) DB::table('reserve_expense_sources')->where('disbursement_id', $id)->sum('amount'));
        $lines = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')->where('event_key', 'fund.spend.'.$id)->get();
        $this->assertCount(2, $lines);
        $this->assertSame((float) $lines->sum('debit'), (float) $lines->sum('credit'));
        $this->assertEquals(250.00, $lines->firstWhere('account', 'company_fund_expense')->debit);
        $this->assertEquals(250.00, $lines->firstWhere('account', 'manual_payout_clearing')->credit);
        $this->assertDatabaseHas('audit_events', ['action' => 'fund.spend_requested', 'subject' => (string) $id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'fund.spend_approved', 'subject' => (string) $id]);
    }

    public function test_a_spend_cannot_take_a_fund_below_zero(): void
    {
        [$maker, $checker] = $this->financeStaff();
        $this->fundRow('company_profit_fund', '300.00');
        $available = $this->available('company_profit') / 100;

        $this->spend($maker, ['amount' => number_format($available + 1, 2, '.', '')])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseMissing('reserve_disbursements', ['program' => 'company_spend']);

        $first = $this->spend($maker, ['amount' => number_format($available, 2, '.', '')])->assertOk()->json('data.id');
        $this->spend($maker, ['amount' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->review($checker, $first, 'approved')->assertOk();
        $this->assertSame(0, $this->balance('company_profit'));
    }

    public function test_the_person_who_recorded_a_spend_cannot_approve_it(): void
    {
        [$maker] = $this->financeStaff();
        $this->fundRow('company_profit_fund', '500.00');
        $id = $this->spend($maker)->assertOk()->json('data.id');

        $this->review($maker, $id, 'approved')->assertUnprocessable();
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'requested']);
    }

    public function test_a_rejected_spend_leaves_the_money_in_the_fund(): void
    {
        [$maker, $checker] = $this->financeStaff();
        $this->fundRow('company_profit_fund', '500.00');
        $before = $this->balance('company_profit');
        $id = $this->spend($maker)->assertOk()->json('data.id');

        $this->review($checker, $id, 'rejected')->assertOk();

        $this->assertSame($before, $this->available('company_profit'));
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'fund.spend.'.$id)->count());
    }

    public function test_users_outside_finance_are_refused(): void
    {
        $this->fundRow('company_profit_fund', '500.00');

        foreach ([User::factory()->create(['role' => 'dealer']), User::factory()->create(['role' => 'sub_dealer'])] as $partner) {
            $this->assertContains($this->spend($partner)->status(), [403, 404]);
        }
        foreach ([User::factory()->staff('sr')->create()] as $user) {
            $this->spend($user)->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/operations/company-funds/heads', ['name_en' => 'Not allowed', 'name_bn' => 'অনুমতি নেই'])->assertForbidden();
        }
        $this->assertDatabaseMissing('reserve_disbursements', ['program' => 'company_spend']);
        $this->actingAs(User::factory()->staff('sr')->create())->onPortal('manage')->get('/treasury/funds')->assertForbidden();
    }

    public function test_an_owner_without_finance_edit_rights_can_record_and_approve(): void
    {
        $this->fundRow('company_profit_fund', '500.00');
        $chairman = User::factory()->staff('chairman')->create();
        $managingDirector = User::factory()->staff('md')->create();

        $id = $this->spend($chairman)->assertOk()->json('data.id');
        $this->review($managingDirector, $id, 'approved')->assertOk();

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'paid']);
    }

    public function test_the_expense_head_list_can_be_added_renamed_and_switched_off(): void
    {
        [$maker] = $this->financeStaff();
        $this->fundRow('company_profit_fund', '500.00');
        $this->assertGreaterThan(5, DB::table('fund_expense_heads')->count(), 'Sensible heads come ready to use.');

        $id = $this->actingAs($maker)->postJson('/api/v1/operations/company-funds/heads', ['name_en' => 'Warehouse guard', 'name_bn' => 'গুদামের পাহারাদার'])->assertOk()->json('data.id');
        $this->patchJson('/api/v1/operations/company-funds/heads/'.$id, ['name_en' => 'Security guard', 'name_bn' => 'নিরাপত্তা প্রহরী', 'is_active' => true])->assertOk();
        $this->assertDatabaseHas('fund_expense_heads', ['id' => $id, 'name_en' => 'Security guard', 'name_bn' => 'নিরাপত্তা প্রহরী']);
        $this->assertDatabaseHas('audit_events', ['action' => 'fund.head_updated', 'subject' => (string) $id]);

        $this->patchJson('/api/v1/operations/company-funds/heads/'.$id, ['name_en' => 'Security guard', 'name_bn' => 'নিরাপত্তা প্রহরী', 'is_active' => false])->assertOk();
        $this->spend($maker, ['expense_head_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('expense_head_id');
    }

    public function test_the_report_totals_match_the_spends_by_fund_and_head(): void
    {
        [$maker, $checker] = $this->financeStaff();
        $this->fundRow('company_profit_fund', '1000.00');
        $this->fundRow('company_marketing_fund', '1000.00');
        $rent = $this->headId('Office rent');
        $bills = $this->headId('Office supplies');
        foreach ([['company_profit', $rent, '100.00'], ['company_profit', $bills, '40.50'], ['marketing', $rent, '60.25']] as [$fund, $head, $amount]) {
            $this->review($checker, $this->spend($maker, ['fund' => $fund, 'expense_head_id' => $head, 'amount' => $amount])->assertOk()->json('data.id'), 'approved')->assertOk();
        }
        $waiting = $this->spend($maker, ['amount' => '10.00'])->assertOk()->json('data.id');

        $report = app(CompanyFunds::class)->report(['from' => '2026-11-01', 'to' => '2026-11-30'], false);

        $this->assertEquals(200.75, (float) $report['total']);
        $this->assertSame(3, $report['count']);
        $this->assertEquals(['company_profit' => 140.50, 'marketing' => 60.25], collect($report['by_fund'])->mapWithKeys(fn ($row) => [$row['fund'] => (float) $row['total']])->all());
        $this->assertEquals([$rent => 160.25, $bills => 40.50], collect($report['by_head'])->mapWithKeys(fn ($row) => [$row['head_id'] => (float) $row['total']])->all());
        $filtered = app(CompanyFunds::class)->report(['from' => '2026-11-01', 'to' => '2026-11-30', 'fund' => 'company_profit', 'head' => $rent], false);
        $this->assertEquals(100.00, (float) $filtered['total']);
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $waiting, 'status' => 'requested']);

        $this->actingAs(User::factory()->staff('finance')->create())->get('/api/v1/operations/reports?type=fund-spending&format=xlsx&from=2026-11-01&to=2026-11-30')->assertOk();
    }

    public function test_no_partner_can_take_tour_money_and_only_the_company_can_spend_it(): void
    {
        [$maker, $checker] = $this->financeStaff();
        $subDealer = User::factory()->create(['role' => 'sub_dealer']);
        $this->fundRow('sub_dealer_tour', '900.00', ['user_id' => $subDealer->id, 'status' => 'reserved']);
        $wallet = fn (): float => (float) DB::table('wallets')->where('user_id', $subDealer->id)->value('available');

        // The partner has no way in.
        $this->assertContains($this->spend($subDealer, ['fund' => 'tour', 'expense_head_id' => $this->headId('Company tour')])->status(), [403, 404]);
        $this->assertContains($this->actingAs($subDealer)->postJson('/api/v1/operations/reserves', ['program' => 'tour', 'user_id' => $subDealer->id, 'amount' => '100.00', 'request_key' => (string) Str::uuid(), 'reason' => 'Asking for my tour money'])->status(), [403, 404]);

        // Office staff cannot push tour money into a partner's wallet either.
        $this->actingAs($maker)->postJson('/api/v1/operations/reserves', ['program' => 'tour', 'user_id' => $subDealer->id, 'amount' => '100.00', 'request_key' => (string) Str::uuid(), 'reason' => 'Tour benefit to one partner'])
            ->assertUnprocessable()->assertJsonValidationErrors('program');
        $old = DB::table('reserve_disbursements')->insertGetId(['program' => 'tour', 'user_id' => $subDealer->id, 'amount' => '100.00', 'request_key' => (string) Str::uuid(), 'reason' => 'Waiting from before the rule', 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($checker)->postJson('/api/v1/operations/reserves/'.$old.'/review', ['decision' => 'approved', 'reason' => 'Approving old request'])->assertUnprocessable();
        $this->assertSame(0.0, $wallet());
        $this->actingAs($checker)->postJson('/api/v1/operations/reserves/'.$old.'/review', ['decision' => 'rejected', 'reason' => 'Clearing the old request'])->assertOk();

        // The company spends it on a tour head only.
        $this->spend($maker, ['fund' => 'tour', 'expense_head_id' => $this->headId('Office rent')])->assertUnprocessable()->assertJsonValidationErrors('expense_head_id');
        $id = $this->spend($maker, ['fund' => 'tour', 'expense_head_id' => $this->headId('Company tour'), 'amount' => '500.00', 'payee' => 'Cox Tour Company'])->assertOk()->json('data.id');
        $this->review($checker, $id, 'approved')->assertOk();

        $this->assertSame(0.0, $wallet());
        $this->assertNull(DB::table('reserve_disbursements')->where('id', $id)->value('user_id'));
        $this->assertSame(40000, app(CompanyFunds::class)->balances()['tour']['balance'] - $this->otherTourMoney());
    }

    public function test_award_funds_keep_their_own_flow(): void
    {
        [$maker] = $this->financeStaff();
        $this->fundRow('car_bike_incentive_fund', '500.00');

        $this->spend($maker, ['fund' => 'car_bike'])->assertUnprocessable()->assertJsonValidationErrors('fund');
        $this->spend($maker, ['fund' => 'rank_reward'])->assertUnprocessable()->assertJsonValidationErrors('fund');
    }

    public function test_the_clinic_fund_shows_a_below_cost_sale_and_is_not_spent_below_zero(): void
    {
        [$maker, $checker] = $this->financeStaff();
        $this->assertSame(0, $this->balance('clinic'));
        $this->spend($maker, ['fund' => 'clinic', 'expense_head_id' => $this->headId('Clinic expenses')])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->clinicSale('300.00', '-50.00', 'RX-1');
        $this->assertSame(-5000, $this->balance('clinic'));
        $this->spend($maker, ['fund' => 'clinic', 'expense_head_id' => $this->headId('Clinic expenses')])->assertUnprocessable();

        $this->clinicSale('300.00', '250.00', 'RX-2');
        $id = $this->spend($maker, ['fund' => 'clinic', 'expense_head_id' => $this->headId('Clinic expenses'), 'amount' => '150.00'])->assertOk()->json('data.id');
        $this->review($checker, $id, 'approved')->assertOk();

        $this->assertSame(5000, $this->balance('clinic'));
        $lines = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')->where('event_key', 'fund.spend.'.$id)->get();
        $this->assertEquals(150.00, $lines->firstWhere('account', 'clinic_fund')->debit);
        $this->assertEquals(150.00, $lines->firstWhere('account', 'clinic_cash')->credit);
    }

    public function test_the_owners_benefits_fund_is_only_for_owners(): void
    {
        [$maker] = $this->financeStaff();

        $this->spend($maker, ['fund' => 'benefits'])->assertForbidden();
        $this->spend(User::factory()->staff('chairman')->create(), ['fund' => 'benefits'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_area_money_with_no_dealer_is_shown_as_company_profit_and_old_rows_stay_as_old_reserve(): void
    {
        $this->fundRow('area_thana', '70.00', ['status' => 'unassigned_reserve', 'metadata' => json_encode(['retained_by' => 'company_profit_fund', 'reason_key' => 'retained_reason_profit_no_area_seat'])]);
        $this->fundRow('area_thana', '30.00', ['status' => 'unassigned_reserve', 'metadata' => json_encode(['unassigned' => true])]);
        $this->fundRow('d2d_gen2_breakage', '20.00', ['status' => 'unassigned_reserve', 'metadata' => json_encode(['unassigned' => true])]);

        $balances = app(CompanyFunds::class)->balances();

        $this->assertSame(3000, $balances['geographic_reserve']['money_in']);
        $this->assertSame(2000, $balances['referral_reserve']['money_in']);
        $this->assertGreaterThanOrEqual(7000, $balances['company_profit']['money_in']);
        $this->assertSame('Older area money, kept from before the company profit rule', CompanyFunds::sourceLabel('geographic_reserve', 'area_thana', 'unassigned_reserve'));
        $this->assertSame('No dealer in this area, so it is company profit', CompanyFunds::sourceLabel('company_profit', 'area_thana', 'unassigned_reserve'));
    }

    public function test_the_company_funds_page_opens_with_each_tab_for_an_owner(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $this->fundRow('company_profit_fund', '500.00');

        foreach (['/treasury/funds', '/treasury/work', '/treasury/work?tab=heads', '/treasury/work?tab=budgets', '/treasury/funds/company_profit', '/treasury/funds/company_share'] as $page) {
            $this->actingAs($owner)->onPortal('sadmin')->get($page)->assertOk();
        }
    }

    /** @return array{0: User, 1: User} */
    private function financeStaff(): array
    {
        return [User::factory()->staff('finance')->create(), User::factory()->staff('finance')->create()];
    }

    private function spend(User $actor, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor)->post('/api/v1/operations/company-funds/spends', [
            'fund' => 'company_profit',
            'expense_head_id' => $this->headId('Office rent'),
            'amount' => '100.00',
            'spent_on' => '2026-11-07',
            'payee' => 'Landlord',
            'payment_method' => 'cash',
            'note' => 'Monthly payment',
            'request_key' => (string) Str::uuid(),
            ...$overrides,
        ], ['Accept' => 'application/json']);
    }

    private function review(User $actor, int $id, string $decision): TestResponse
    {
        return $this->actingAs($actor)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => $decision, 'reason' => 'Checked against the bill']);
    }

    private function headId(string $name): int
    {
        return (int) DB::table('fund_expense_heads')->where('name_en', $name)->value('id');
    }

    private function balance(string $fund): int
    {
        return app(CompanyFunds::class)->balances()[$fund]['balance'];
    }

    private function available(string $fund): int
    {
        return app(CompanyFunds::class)->balances()[$fund]['available'];
    }

    /** Tour money the posted order itself saved for its own buyer. */
    private function otherTourMoney(): int
    {
        return (int) round(((float) DB::table('commission_allocations')->where('category', 'sub_dealer_tour')->where('order_id', $this->order->id)->where('amount', '<>', '900.00')->sum('amount')) * 100);
    }

    /** @param array<string, mixed> $overrides */
    private function fundRow(string $category, string $amount, array $overrides = []): int
    {
        return DB::table('commission_allocations')->insertGetId([
            'order_id' => $this->order->id,
            'category' => $category,
            'rate_percentage' => '0.00',
            'cash_basis' => '1000.00',
            'amount' => $amount,
            'original_amount' => $amount,
            'status' => 'reserved',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function clinicSale(string $manufacturerShare, string $clinicFund, string $number): void
    {
        $patient = DB::table('prescription_patients')->insertGetId(['name' => 'Patient '.$number, 'prescriber_id' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prescriptions')->insert([
            'number' => $number, 'kind' => 'clinic', 'patient_id' => $patient, 'prescriber_id' => User::factory()->create()->id, 'prescribed_on' => '2026-11-07',
            'subtotal' => '300.00', 'discount_total' => '0.00', 'total' => '300.00', 'status' => 'dispensed', 'manufacturer_share' => $manufacturerShare, 'clinic_fund' => $clinicFund,
            'dispensed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function postedOrder(): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create(User::factory()->create(['role' => 'sub_dealer']), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => '120.00']);
        app(CommissionPosting::class)->process($order);

        return $order;
    }
}
