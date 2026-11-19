<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerIncomeHeads;
use App\Domain\Accounts\PartnerMoneyBook;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Commissions\RecipientFunding;
use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\ReserveService;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CR-21 / V2: every income head has one name and one place, zero heads are listed, and the heads add up
 * to exactly what the wallet's income cards added up to before.
 */
class PartnerIncomeHeadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_commission_category_kind_and_program_is_named_by_a_head_on_purpose(): void
    {
        foreach (RecipientFunding::Sources as $category) {
            $this->assertTrue(PartnerIncomeHeads::names('category', $category), "Category {$category} has no head.");
        }
        foreach (['salary', 'royalty', ...array_map(fn (string $rank): string => 'rank_'.$rank, CommissionSettlement::LeadershipRanks)] as $kind) {
            $this->assertTrue(PartnerIncomeHeads::names('kind', $kind), "Settlement kind {$kind} has no head.");
            $this->assertNotSame('portal.settle_kind_'.$kind, (string) __('portal.settle_kind_'.$kind), "Settlement kind {$kind} has no word.");
        }
        foreach (array_keys(ReserveService::Programs) as $program) {
            $this->assertTrue(PartnerIncomeHeads::names('program', $program), "Reward program {$program} has no head.");
        }
        foreach (PartnerMoneyBook::IncomeTypes as $type) {
            $this->assertContains(PartnerIncomeHeads::headOf($type, null, null, null), PartnerIncomeHeads::keys());
        }
        $this->assertSame('opening', PartnerIncomeHeads::headOf('opening', null, null, null));
    }

    public function test_a_row_goes_to_the_head_the_client_expects(): void
    {
        $expected = [
            ['own', 'own_dealer_cashback', null, null, 'own'],
            ['referral', 'direct_referral', null, null, 'referral'],
            ['team', 'd2d_gen2', null, null, 'd2d_gen2'],
            ['team', 'd2d_gen3', null, null, 'd2d_gen3'],
            ['area', 'area_division', null, null, 'area_division'],
            ['area', 'area_union', null, null, 'area_union'],
            ['salary', null, null, 'salary', 'salary'],
            ['salary', 'sub_dealer_salary', null, null, 'salary'],
            ['rank_bonus', null, null, 'rank_me', 'rank_me'],
            ['royalty', null, null, 'royalty', 'royalty'],
            ['rank_bonus', 'rank_incentive_pool', null, null, 'incentive'],
            ['reward', null, 'additional_rank', null, 'incentive'],
            ['reward', null, 'bike', null, 'bike'],
            ['reward', null, 'car', null, 'car'],
            ['reward', null, 'tour', null, 'tour'],
            ['reward', 'sub_dealer_tour', null, null, 'tour'],
            ['reward', null, 'marketing', null, 'other'],
            ['reward', null, null, null, 'other'],
            ['opening', null, null, null, 'opening'],
        ];
        foreach ($expected as [$type, $category, $program, $kind, $head]) {
            $this->assertSame($head, PartnerIncomeHeads::headOf($type, $category, $program, $kind), "{$type}/{$category}/{$program}/{$kind}");
        }
    }

    public function test_the_sql_and_the_php_name_the_same_head_for_every_kind_of_row(): void
    {
        $rows = [['own', 'own_dealer_cashback', null, null], ['referral', 'direct_referral', null, null], ['team', 'd2d_gen3', null, null],
            ['area', 'area_upazila', null, null], ['area', 'area_management', null, null], ['salary', null, null, 'salary'], ['salary', 'dealer_monthly_salary_pool', null, null],
            ['rank_bonus', null, null, 'rank_mo'], ['rank_bonus', null, null, 'rank_am'], ['rank_bonus', null, null, 'rank_sm'], ['rank_bonus', null, null, 'rank_me'],
            ['rank_bonus', null, null, 'rank_unknown'], ['rank_bonus', 'rank_incentive_pool', null, null], ['royalty', null, null, 'royalty'], ['royalty', 'rank_royalty_pool', null, null],
            ['reward', null, 'bike', null], ['reward', null, 'car', null], ['reward', null, 'tour', null], ['reward', null, 'additional_rank', null], ['reward', null, 'marketing', null],
            ['reward', 'additional_rank_pool', null, null], ['reward', 'car_bike_incentive_fund', null, null], ['reward', 'company_marketing_fund', null, null], ['reward', null, null, null],
            ['opening', null, null, null], ['returned', null, null, null], ['withdrawn', null, null, null], ['spent', null, null, null], ['taken_back', null, null, null]];
        $values = implode(', ', array_map(fn (array $row): string => '('.implode(', ', array_map(fn (?string $value): string => $value === null ? 'NULL::text' : "'".$value."'", $row)).')', $rows));
        $found = DB::select('SELECT t.type, t.category, t.program, t.kind, '.PartnerIncomeHeads::sql('t')." AS head FROM (VALUES {$values}) AS t(type, category, program, kind)");

        $this->assertCount(count($rows), $found);
        foreach ($found as $row) {
            if (in_array($row->type, PartnerIncomeHeads::RowTypes, true)) {
                $this->assertSame(PartnerIncomeHeads::headOf($row->type, $row->category, $row->program, $row->kind), $row->head, "{$row->type}/{$row->category}/{$row->program}/{$row->kind}");
            } else {
                $this->assertNull($row->head, 'A withdrawal or refund is never income.');
            }
        }
    }

    public function test_no_two_heads_share_a_label_and_every_head_has_both_languages(): void
    {
        $bangla = require lang_path('bn/cr21_income.php');
        $english = require lang_path('en/cr21_income.php');

        $this->assertSame(array_keys($bangla), array_keys($english));
        foreach (PartnerIncomeHeads::keys() as $head) {
            foreach (['head_', 'hint_'] as $prefix) {
                $this->assertNotSame('', trim($bangla[$prefix.$head] ?? ''), $prefix.$head);
                $this->assertNotSame('', trim($english[$prefix.$head] ?? ''), $prefix.$head);
            }
        }
        foreach ([$bangla, $english] as $words) {
            $labels = array_map(fn (string $head): string => $words['head_'.$head], PartnerIncomeHeads::keys());
            $this->assertSame($labels, array_values(array_unique($labels)), 'Two heads share a label.');
        }
        $this->assertStringEndsWith('MO', $bangla['head_rank_mo']);
    }

    public function test_the_income_page_lists_every_head_of_a_dealer_even_at_zero_and_adds_up_to_the_wallet(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->seedIncome($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->get('/my-income?period=year')->assertOk()->assertInertia(function (Assert $page) use ($dealer): void {
            $props = $page->toArray()['props']['income'];
            $parts = collect($props['parts']);
            $this->assertSame(PartnerIncomeHeads::forRole('dealer'), array_values(array_diff($parts->pluck('key')->all(), ['opening'])), 'A dealer sees every dealer head, in order.');
            $total = $parts->pluck('total', 'key');
            $this->assertSame('50.00', $total['own']);
            $this->assertSame('0.00', $total['referral'], 'A head with no money is still a row.');
            $this->assertSame('0.00', $total['tour'], 'DECISION: dealers see the tour head at zero.');
            $this->assertSame('36.00', $total['area_union']);
            $this->assertSame('170.00', $total['salary']);
            $this->assertSame('60.00', $total['rank_mo']);
            $this->assertSame('25.00', $total['rank_sm']);
            $this->assertSame('0.00', $total['rank_am']);
            $this->assertSame('45.00', $total['royalty']);
            $this->assertSame('15.00', $total['bike']);
            $this->assertSame('8.00', $total['incentive']);
            $this->assertSame('5.00', $total['other']);
            $this->assertSame('12.00', $total['opening']);

            $cards = app(PartnerMoneyBook::class)->incomeCards($dealer);
            $cardsTotal = array_sum(array_map(fn (array $card): int => FundCatalog::centsOf($card['amount']), $cards));
            $this->assertSame($props['sum']['total'], FundCatalog::decimalOf($cardsTotal), 'The total at the bottom is the wallet income cards total.');
        });
    }

    public function test_the_heads_add_up_to_what_the_old_cards_added_up_to(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->seedIncome($dealer);
        $book = app(PartnerMoneyBook::class);

        $byHeadCents = array_sum(array_column($book->totalsByHead($dealer), 'cents'));
        $byTypeCents = FundCatalog::centsOf((string) DB::query()->fromSub($book->headRows((int) $dealer->id), 'h')
            ->whereIn('h.type', [...PartnerMoneyBook::IncomeTypes, 'opening'])->sum('h.amount'));
        $this->assertSame($byTypeCents, $byHeadCents, 'Grouping by head and grouping by the old type give one figure.');
        $this->assertSame($byHeadCents, FundCatalog::centsOf($book->summary($dealer)['lifetime']['earned']) + 1200, 'Earned plus the opening balance is the heads total.');
        $this->assertSame($byHeadCents, array_sum(array_map(fn (array $card): int => FundCatalog::centsOf($card['amount']), $book->incomeCards($dealer))));
    }

    public function test_a_sub_dealer_sees_the_heads_that_apply_to_them_and_any_head_that_holds_money(): void
    {
        $sub = User::factory()->subDealer()->create(['status' => 'active']);
        $this->credited($sub, 'sub_dealer_salary', '30.00', 'available');
        $this->entry('commissions.settlement.9.'.$sub->id.'.rank_mo', $sub, '7.00');

        $this->actingAs($sub)->onPortal('subdealer')->get('/my-income?period=year')->assertOk()->assertInertia(function (Assert $page): void {
            $parts = collect($page->toArray()['props']['income']['parts']);
            $keys = $parts->pluck('key')->all();
            foreach (['own', 'referral', 'salary', 'incentive', 'tour', 'other'] as $head) {
                $this->assertContains($head, $keys);
            }
            foreach (['area_division', 'area_union', 'rank_am', 'royalty', 'bike', 'car'] as $head) {
                $this->assertNotContains($head, $keys, "A sub-dealer has no {$head} head.");
            }
            $this->assertContains('rank_mo', $keys, 'Money in a head always shows, so the total still matches the wallet.');
            $this->assertSame('30.00', $parts->firstWhere('key', 'salary')['total']);
        });
    }

    public function test_a_head_opens_its_own_entries_and_the_wallet_filters_by_head(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->seedIncome($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->get('/my-income?part=rank_mo&period=year')->assertInertia(function (Assert $page): void {
            $page->where('income.part', 'rank_mo')->where('income.table.totals.count', 1)->where('income.table.rows.data.0.amount', '60.00')->where('income.table.rows.data.0.what', __('cr21_income.head_rank_mo'));
        });
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-income?part=opening&period=year')->assertInertia(function (Assert $page): void {
            $page->where('income.table.totals.count', 1)->where('income.table.rows.data.0.amount', '12.00');
        });
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-income/rank_mo/pdf?period=year')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money?head=royalty')->assertOk()->assertInertia(function (Assert $page): void {
            $page->where('table.totals.count', 1)->where('table.rows.data.0.type_label', __('cr21_income.head_royalty'))->where('table.rows.data.0.running', null);
        });
        $cards = collect(app(PartnerMoneyBook::class)->incomeCards($dealer))->pluck('label', 'key');
        $this->assertSame(__('cr21_income.head_bike'), $cards['bike']);
        $this->assertArrayNotHasKey('referral', $cards->all(), 'The wallet keeps hiding zero heads.');
    }

    /** One dealer with a little in many heads. */
    private function seedIncome(User $dealer): void
    {
        $this->credited($dealer, 'own_dealer_cashback', '50.00', 'available');
        $this->credited($dealer, 'area_union', '16.00', 'maturing', ['maturation_due_at' => '2026-12-20']);
        $this->credited($dealer, 'area_union', '20.00', 'available');
        $this->credited($dealer, 'rank_incentive_pool', '8.00', 'available');
        $this->entry('commissions.settlement.salary.'.now()->format('Y-m').'.a', $dealer, '100.00');
        $this->entry('commissions.settlement.salary.'.now()->subMonths(2)->format('Y-m').'.b', $dealer, '70.00');
        $this->entry('commissions.settlement.4.'.$dealer->id.'.rank_mo', $dealer, '60.00');
        $this->entry('commissions.settlement.5.'.$dealer->id.'.rank_sm', $dealer, '25.00');
        $this->entry('commissions.settlement.royalty.'.now()->format('Y-m').'.c', $dealer, '45.00');
        $this->entry('reserve.award.'.DB::table('reserve_disbursements')->insertGetId($this->disbursement('bike', '15.00')), $dealer, '15.00');
        $this->entry('reserve.award.'.DB::table('reserve_disbursements')->insertGetId($this->disbursement('marketing', '5.00')), $dealer, '5.00');
        $this->entry('opening.hold_release.1', $dealer, '12.00');
    }

    /** @return array<string, mixed> */
    private function disbursement(string $program, string $amount): array
    {
        $maker = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        return ['program' => $program, 'user_id' => null, 'amount' => $amount, 'request_key' => (string) Str::uuid(), 'reason' => 'Award', 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()];
    }

    /** @param  array<string, mixed>  $metadata */
    private function credited(User $partner, string $category, string $amount, string $status, array $metadata = []): int
    {
        $order = $this->order($partner);
        DB::table('journal_entries')->insert(['id' => (string) Str::uuid(), 'event_key' => 'commissions.order.'.$order->id, 'description' => 'Commission', 'created_at' => now()]);

        return (int) DB::table('commission_allocations')->insertGetId([
            'order_id' => $order->id, 'user_id' => $partner->id, 'category' => $category, 'rate_percentage' => '10.00', 'cash_basis' => '1000.00',
            'amount' => $amount, 'original_amount' => $amount, 'status' => $status, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function entry(string $key, User $partner, string $change): void
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => $key, 'description' => 'Entry', 'created_at' => now()]);
        DB::table('journal_lines')->insert(['entry_id' => $id, 'user_id' => $partner->id, 'account' => 'wallet_liability', 'debit' => '0.00', 'credit' => $change]);
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
