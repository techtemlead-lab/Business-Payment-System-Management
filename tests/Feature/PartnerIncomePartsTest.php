<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerIncomeBook;
use App\Domain\Accounts\PartnerMoneyBook;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerIncomePartsTest extends TestCase
{
    use RefreshDatabase;

    private User $dealer;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($this->dealer->id, null);
        $this->member = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $this->dealer->id]);
        ReferralTreePath::attachUser($this->member->id, $this->dealer->id);
        $this->travelTo(now('UTC')->setDate(2026, 11, 9)->setTime(8, 0));

        $this->credited($this->dealer, 'own_dealer_cashback', '50.00', 'available');
        $this->credited($this->dealer, 'area_union', '16.00', 'maturing', ['maturation_due_at' => '2026-11-20']);
        $this->credited($this->dealer, 'area_union', '15.60', 'held');
        $released = $this->credited($this->dealer, 'area_union', '20.00', 'available', ['released_at' => now()->toIso8601String()]);
        $this->entry('commission.hold_release.'.$released, $this->dealer, '20.00');
        $this->entry('reserve.award.1', $this->dealer, '10.00');
        $this->entry('commissions.settlement.salary.2026-11.x', $this->dealer, '100.00');
        $this->entry('commissions.settlement.salary.2026-09.y', $this->dealer, '70.00', now()->subMonths(2));

        $this->credited($this->member, 'direct_referral', '40.00', 'available');
    }

    public function test_every_income_is_its_own_part_with_a_total_and_the_parts_add_up_to_what_my_money_earned(): void
    {
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/my-income?period=year')->assertOk()->assertInertia(function (Assert $page): void {
            $page->component('MyIncome')->where('income.part', 'own');
            $amounts = collect($page->toArray()['props']['income']['parts'])->pluck('amount', 'key');
            $this->assertSame('50.00', $amounts['own']);
            $this->assertSame('36.00', $amounts['area']);
            $this->assertSame('170.00', $amounts['salary']);
            $this->assertSame('10.00', $amounts['reward']);
            $this->assertSame('0.00', $amounts['referral']);
            $this->assertSame(array_sum(array_map('floatval', $amounts->all())), (float) app(PartnerMoneyBook::class)->summary($this->dealer)['lifetime']['earned']);
        });
    }

    public function test_the_default_view_is_this_month_and_a_chosen_range_brings_in_older_days(): void
    {
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/my-income?part=salary')->assertInertia(function (Assert $page): void {
            $page->where('income.part', 'salary')->where('income.table.totals.count', 1)->where('income.table.rows.data.0.amount', '100.00');
        });
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/my-income?part=salary&period=custom&from=2026-09-01&to=2026-09-30')->assertInertia(function (Assert $page): void {
            $page->where('income.table.totals.count', 1)->where('income.table.rows.data.0.amount', '70.00');
        });
    }

    public function test_a_row_says_whether_it_is_waiting_or_ready_and_can_be_filtered_and_searched(): void
    {
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/my-income?part=area&status=waiting')->assertInertia(function (Assert $page): void {
            $page->where('income.table.totals.count', 1)->where('income.table.rows.data.0.status', 'waiting')->where('income.table.rows.data.0.amount', '16.00');
        });
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/my-income?part=area&status=ready&sort=amount&dir=asc')->assertInertia(function (Assert $page): void {
            $page->where('income.table.totals.count', 1)->where('income.table.rows.data.0.status', 'ready');
        });
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/my-income?part=area&q=NO-SUCH-ORDER')->assertInertia(fn (Assert $page) => $page->where('income.table.totals.count', 0));
    }

    public function test_money_taken_back_after_a_refund_is_taken_off_its_part(): void
    {
        $takenBack = $this->credited($this->dealer, 'direct_referral', '30.00', 'reversed');
        $refundId = (string) Str::uuid();
        DB::table('commission_refunds')->insert(['id' => $refundId, 'order_id' => DB::table('commission_allocations')->where('id', $takenBack)->value('order_id'), 'reference' => 'R-1', 'amount' => '30.00', 'reason' => 'Returned', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('commission_reversals')->insert(['refund_id' => $refundId, 'allocation_id' => $takenBack, 'user_id' => $this->dealer->id, 'amount' => '30.00', 'recovered' => '30.00', 'unrecovered' => '0.00', 'source_status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $this->entry('commissions.reversal.'.$refundId, $this->dealer, '-30.00');

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/my-income?part=referral')->assertInertia(function (Assert $page): void {
            $page->where('income.table.totals.count', 2)->where('income.table.totals.sums.amount', '0.00');
        });
    }

    public function test_a_partner_sees_only_their_own_income(): void
    {
        $this->actingAs($this->member)->onPortal('dealer')->get('/my-income?part=referral&period=year')->assertInertia(function (Assert $page): void {
            $page->where('income.table.totals.count', 1)->where('income.table.rows.data.0.amount', '40.00');
        });
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/my-income?part=referral&period=year')->assertInertia(fn (Assert $page) => $page->where('income.table.totals.count', 0));
    }

    public function test_a_sub_dealer_gets_the_page_and_a_signed_out_visitor_does_not(): void
    {
        $sub = User::factory()->subDealer()->create(['status' => 'active']);
        $this->actingAs($sub)->onPortal('subdealer')->get('/my-income')->assertOk()->assertInertia(fn (Assert $page) => $page->component('MyIncome'));
        auth()->logout();
        $this->onPortal('dealer')->get('/my-income')->assertRedirect();
    }

    public function test_a_part_prints_as_its_own_pdf_with_the_partner_on_top(): void
    {
        $response = $this->actingAs($this->member)->onPortal('dealer')->get('/my-income/referral/pdf?period=year')->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'report.exported')->where('subject', 'partner-income-statement')->count());
        $this->actingAs($this->member)->onPortal('dealer')->get('/my-income/nonsense/pdf')->assertNotFound();
    }

    public function test_office_staff_with_partner_access_see_the_same_breakdown_and_others_do_not(): void
    {
        foreach (['account_manager', 'finance'] as $role) {
            $staff = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($staff)->onPortal('manage')->get('/treasury/partners/'.$this->dealer->id.'?view=income&period=year')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('TreasuryPartner')->where('view', 'income')->where('table', null)->where('income.parts.0.amount', '50.00'));
            $this->actingAs($staff)->onPortal('manage')->get('/treasury/partners/'.$this->dealer->id.'/income/salary/pdf?period=year')->assertOk()->assertHeader('content-type', 'application/pdf');
        }
        $this->actingAs(User::factory()->create(['role' => 'inventory', 'status' => 'active']))->onPortal('manage')
            ->get('/treasury/partners/'.$this->dealer->id.'/income/salary/pdf')->assertForbidden();
        $this->actingAs($this->member)->onPortal('dealer')->get('/treasury/partners/'.$this->dealer->id.'/income/salary/pdf')->assertForbidden();
    }

    public function test_the_money_view_of_the_office_page_is_unchanged(): void
    {
        $staff = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $this->actingAs($staff)->onPortal('manage')->get('/treasury/partners/'.$this->dealer->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('view', 'money')->where('income', null)->has('table.rows.data'));
    }

    public function test_every_income_word_is_there_in_both_languages(): void
    {
        $bangla = require lang_path('bn/income.php');
        $english = require lang_path('en/income.php');

        $this->assertSame(array_keys($bangla), array_keys($english));
        $this->assertSame([], array_filter($bangla, fn (string $word): bool => trim($word) === ''));
        foreach (PartnerIncomeBook::parts() as $part) {
            $this->assertArrayHasKey('part_'.$part, $bangla);
            $this->assertArrayHasKey('hint_'.$part, $bangla);
        }
    }

    /** @param  array<string, string>  $metadata */
    private function credited(User $partner, string $category, string $amount, string $status, array $metadata = []): int
    {
        $order = $this->order($partner);
        DB::table('journal_entries')->insert(['id' => (string) Str::uuid(), 'event_key' => 'commissions.order.'.$order->id, 'description' => 'Commission', 'created_at' => now()]);

        return (int) DB::table('commission_allocations')->insertGetId([
            'order_id' => $order->id, 'user_id' => $partner->id, 'category' => $category, 'rate_percentage' => '10.00', 'cash_basis' => '1000.00',
            'amount' => $status === 'reversed' ? '0.00' : $amount, 'original_amount' => $amount, 'status' => $status, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function entry(string $key, User $partner, string $change, mixed $at = null): void
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => $key, 'description' => 'Entry', 'created_at' => $at ?? now()]);
        $credit = (float) $change > 0;
        DB::table('journal_lines')->insert(['entry_id' => $id, 'user_id' => $partner->id, 'account' => 'wallet_liability', 'debit' => $credit ? '0.00' : ltrim($change, '-'), 'credit' => $credit ? $change : '0.00']);
    }

    private function order(User $buyer): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::random(8), 'user_id' => $buyer->id, 'status' => 'delivered', 'channel' => 'dealer', 'currency' => 'BDT',
            'subtotal' => '1000.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => '1000.00', 'paid' => '1000.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random()),
        ]);
    }
}
