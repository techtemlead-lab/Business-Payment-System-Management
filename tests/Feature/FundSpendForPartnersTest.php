<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\FundSpendPartners;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A company-fund spend can be for the company itself (as always), chosen partners, or every partner of a type. Each named partner
 * sees their own share as a "benefit from the company": it is never a wallet credit, a pending amount or an income total, and
 * the ledger posting, fund balance and approval rules are exactly as they were.
 */
class FundSpendForPartnersTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
        $this->order = $this->postedOrder();
        $this->maker = User::factory()->staff('finance')->create();
        $this->checker = User::factory()->staff('finance')->create();
        $this->fundRow('company_profit_fund', '100000.00');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function postedOrder(): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create(User::factory()->create(['role' => 'sub_dealer']), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => '120.00']);
        app(CommissionPosting::class)->process($order);

        return $order;
    }

    private function fundRow(string $category, string $amount): void
    {
        DB::table('commission_allocations')->insert([
            'order_id' => $this->order->id, 'category' => $category, 'rate_percentage' => '0.00', 'cash_basis' => '1000.00', 'amount' => $amount, 'original_amount' => $amount,
            'status' => 'reserved', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function partner(string $role = 'dealer', string $name = 'Partner'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'name' => $name.' '.Str::random(4)]);
    }

    /** @param array<string, mixed> $overrides */
    private function spend(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->maker)->post('/api/v1/operations/company-funds/spends', [
            'fund' => 'company_profit', 'expense_head_id' => (int) DB::table('fund_expense_heads')->where('name_en', 'Office rent')->value('id'),
            'amount' => '300.00', 'spent_on' => '2026-11-07', 'payee' => 'Hotel Rangpur', 'payment_method' => 'cash', 'note' => 'Annual dealer tour',
            'request_key' => (string) Str::uuid(), ...$overrides,
        ], ['Accept' => 'application/json']);
    }

    private function approve(int $id): void
    {
        $this->actingAs($this->checker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked against the bill'])->assertOk();
    }

    /** @return array<string, mixed> */
    private function myMoney(User $partner): array
    {
        return $this->actingAs($partner)->onPortal($partner->role === 'sub_dealer' ? 'subdealer' : 'dealer')->get('/my-money')->assertOk()->viewData('page')['props'];
    }

    private function wallet(User $partner): string
    {
        return (string) (DB::table('wallets')->where('user_id', $partner->id)->value('available') ?? '0.00');
    }

    public function test_a_spend_for_no_one_is_for_the_company_as_today_and_has_no_partner_rows(): void
    {
        $partner = $this->partner();
        $id = $this->spend()->assertOk()->json('data.id');
        $this->approve($id);

        $this->assertSame(0, DB::table('fund_spend_partners')->count());
        $this->assertSame([], $this->myMoney($partner)['benefits']['lines']);

        $explicit = $this->spend(['for' => ['mode' => 'company']])->assertOk()->json('data.id');
        $this->assertSame(0, DB::table('fund_spend_partners')->where('disbursement_id', $explicit)->count());
    }

    public function test_a_spend_for_one_partner_shows_in_every_place_and_changes_no_balance(): void
    {
        $partner = $this->partner('dealer', 'Rafiq');
        $other = $this->partner('dealer', 'Other');
        $walletBefore = $this->wallet($partner);
        $pendingBefore = $this->myMoney($partner)['pending']['total'];
        $summaryBefore = $this->myMoney($partner)['summary'];

        $id = $this->spend(['for' => ['mode' => 'partners', 'partner_ids' => [$partner->id]]])->assertOk()->json('data.id');
        $this->assertSame([], $this->myMoney($partner)['benefits']['lines'], 'A spend that is only recorded is not shown until it is approved.');
        $this->approve($id);

        $benefits = $this->myMoney($partner)['benefits'];
        $this->assertCount(1, $benefits['lines']);
        $this->assertSame('300.00', $benefits['lines'][0]['amount']);
        $this->assertSame('paid_by_office', $benefits['lines'][0]['status']);
        $this->assertSame('Annual dealer tour', $benefits['lines'][0]['purpose']);
        $this->assertStringContainsString('Office rent', $benefits['lines'][0]['what']);
        $this->assertSame('300.00', $benefits['total']);

        // It is money the company spent on them: no wallet, pending or income change, and no journal line for the partner.
        $this->assertSame($walletBefore, $this->wallet($partner));
        $this->assertSame($pendingBefore, $this->myMoney($partner)['pending']['total']);
        $this->assertSame($summaryBefore, $this->myMoney($partner)['summary']);
        $this->assertSame(0, DB::table('journal_lines')->where('user_id', $partner->id)->count());
        $this->assertSame(2, DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')->where('event_key', 'fund.spend.'.$id)->count(), 'The ledger posting is the same two lines as before.');

        // The income page, the dashboard and the office partner page show the same line; nobody else sees it.
        $this->actingAs($partner)->onPortal('dealer')->get('/my-income')->assertOk()->assertInertia(fn (Assert $page) => $page->where('benefits.lines.0.amount', '300.00'));
        $this->assertSame('300.00', app(PartnerDashboardHome::class)->for($partner)['wallet']['benefits']['lines'][0]['amount']);
        $this->actingAs($this->maker)->onPortal('manage')->get('/treasury/partners/'.$partner->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('TreasuryPartner')->where('benefits.lines.0.amount', '300.00'));
        $this->assertSame([], $this->myMoney($other)['benefits']['lines']);
        $this->assertSame([], app(PartnerDashboardHome::class)->for($other)['wallet']['benefits']['lines']);
    }

    public function test_a_spend_for_several_partners_is_split_equally_and_the_shares_add_up_exactly(): void
    {
        $three = [$this->partner(), $this->partner(), $this->partner()];
        $outsider = $this->partner();
        $id = $this->spend(['amount' => '100.00', 'for' => ['mode' => 'partners', 'partner_ids' => array_map(fn (User $user): int => $user->id, $three)]])->assertOk()->json('data.id');
        $this->approve($id);

        $shares = DB::table('fund_spend_partners')->where('disbursement_id', $id)->orderBy('user_id')->pluck('share_amount', 'user_id')->map(fn ($share): string => (string) $share)->all();
        $this->assertCount(3, $shares);
        $this->assertSame(10000, (int) round(array_sum(array_map('floatval', $shares)) * 100), 'The shares add up to the spend.');
        $this->assertEqualsCanonicalizing(['33.33', '33.33', '33.34'], array_values($shares));
        foreach ($three as $partner) {
            $this->assertSame($shares[$partner->id], $this->myMoney($partner)['benefits']['lines'][0]['amount']);
        }
        $this->assertSame([], $this->myMoney($outsider)['benefits']['lines']);
    }

    public function test_all_dealers_all_sub_dealers_and_everyone_reach_the_right_partners(): void
    {
        $dealers = [$this->partner('dealer'), $this->partner('dealer')];
        $subs = [$this->partner('sub_dealer'), $this->partner('sub_dealer'), $this->partner('sub_dealer')];
        User::factory()->create(['role' => 'dealer', 'status' => 'suspended']);
        // The sub-dealer who placed the setUp order is an active sub-dealer too.
        $buyer = (int) $this->order->user_id;
        $allSubs = [...array_map(fn (User $user): int => $user->id, $subs), $buyer];
        sort($allSubs);

        $ids = fn (string $mode): array => app(FundSpendPartners::class)->resolve(['mode' => $mode]);
        $dealerIds = array_map(fn (User $user): int => $user->id, $dealers);
        $this->assertSame($dealerIds, $ids('all_dealers'));
        $this->assertSame($allSubs, $ids('all_sub_dealers'));
        $everyone = [...$dealerIds, ...$allSubs];
        sort($everyone);
        $this->assertSame($everyone, $ids('everyone'));

        $id = $this->spend(['amount' => '200.00', 'for' => ['mode' => 'all_dealers']])->assertOk()->json('data.id');
        $this->approve($id);
        $this->assertSame('100.00', $this->myMoney($dealers[0])['benefits']['lines'][0]['amount']);
        $this->assertSame([], $this->myMoney($subs[0])['benefits']['lines']);
    }

    public function test_the_partner_picker_finds_active_partners_by_name_code_and_phone(): void
    {
        $partner = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'name' => 'Rafiq Hasan', 'phone' => '01712345678']);
        User::factory()->create(['role' => 'dealer', 'status' => 'suspended', 'name' => 'Rafiq Closed']);

        $byName = $this->actingAs($this->maker)->getJson('/api/v1/operations/company-funds/partners?q=Rafiq')->assertOk()->json();
        $this->assertSame([$partner->id], array_column($byName['partners'], 'id'));
        $this->assertGreaterThanOrEqual(1, $byName['counts']['dealers']);
        $this->assertSame([$partner->id], array_column($this->actingAs($this->maker)->getJson('/api/v1/operations/company-funds/partners?q=0171234')->json('partners'), 'id'));
        $this->assertSame([], $this->actingAs($this->maker)->getJson('/api/v1/operations/company-funds/partners?q=R')->json('partners'));
        $this->actingAs($this->partner())->getJson('/api/v1/operations/company-funds/partners?q=Rafiq')->assertNotFound();
    }

    public function test_a_bad_choice_is_refused_and_nothing_is_written(): void
    {
        $partner = $this->partner();
        $closed = User::factory()->create(['role' => 'dealer', 'status' => 'suspended']);
        $staff = User::factory()->staff('finance')->create();

        $this->spend(['for' => ['mode' => 'partners', 'partner_ids' => []]])->assertUnprocessable();
        $this->spend(['for' => ['mode' => 'partners', 'partner_ids' => [$closed->id]]])->assertUnprocessable();
        $this->spend(['for' => ['mode' => 'partners', 'partner_ids' => [$staff->id]]])->assertUnprocessable();
        $this->spend(['for' => ['mode' => 'nobody']])->assertUnprocessable();
        $this->spend(['for' => ['mode' => 'partners', 'partner_ids' => [$partner->id, 999999]]])->assertUnprocessable();

        $this->assertSame(0, DB::table('reserve_disbursements')->where('program', 'company_spend')->count(), 'A refused choice writes no spend at all.');
        $this->assertSame(0, DB::table('fund_spend_partners')->count());
    }

    public function test_a_rejected_or_withdrawn_spend_never_shows_and_the_approval_rules_are_unchanged(): void
    {
        $partner = $this->partner();
        $rejected = $this->spend(['for' => ['mode' => 'partners', 'partner_ids' => [$partner->id]]])->assertOk()->json('data.id');
        $this->actingAs($this->checker)->postJson('/api/v1/operations/company-funds/spends/'.$rejected.'/review', ['decision' => 'rejected', 'reason' => 'Not allowed this time'])->assertOk();
        $own = $this->spend(['for' => ['mode' => 'partners', 'partner_ids' => [$partner->id]]])->assertOk()->json('data.id');
        $this->actingAs($this->maker)->postJson('/api/v1/operations/company-funds/spends/'.$own.'/review', ['decision' => 'approved', 'reason' => 'Checked against the bill'])->assertUnprocessable();

        $this->assertSame([], $this->myMoney($partner)['benefits']['lines']);
        $this->assertSame('requested', DB::table('reserve_disbursements')->where('id', $own)->value('status'));
    }

    public function test_the_office_approval_list_says_who_a_waiting_spend_is_for(): void
    {
        $partner = $this->partner();
        $this->spend(['for' => ['mode' => 'partners', 'partner_ids' => [$partner->id]]])->assertOk();
        $owner = User::factory()->staff('super_admin')->create();

        $rows = $this->actingAs($owner)->onPortal('sadmin')->get('/treasury/work')->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString('"for_partners":1', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function test_the_readiness_checks_stay_at_eighteen_and_the_books_still_match(): void
    {
        $partner = $this->partner();
        $before = app(FinancialReadiness::class)->booksMatch();
        $id = $this->spend(['for' => ['mode' => 'everyone']])->assertOk()->json('data.id');
        $this->approve($id);

        $this->assertCount(18, app(FinancialReadiness::class)->checks());
        $this->assertSame($before, app(FinancialReadiness::class)->booksMatch());
        $this->assertSame('0.00', $this->wallet($partner));
    }

    public function test_the_new_words_exist_in_both_languages(): void
    {
        foreach (['funds' => ['for_title', 'for_company', 'for_partners', 'for_all_dealers', 'for_all_sub_dealers', 'for_everyone', 'for_search', 'for_remove', 'for_none', 'for_each', 'for_hint', 'for_spend_partners',
            'err_for_mode', 'err_for_pick', 'err_for_partner', 'err_for_none'], 'my_money' => ['benefits_heading', 'benefits_intro', 'benefits_status_paid_by_office', 'benefits_more', 'benefits_total']] as $group => $keys) {
            foreach (['bn', 'en'] as $locale) {
                $words = require lang_path($locale.'/'.$group.'.php');
                foreach ($keys as $key) {
                    $this->assertNotSame('', trim((string) ($words[$key] ?? '')), "{$locale}/{$group}.{$key}");
                }
            }
        }
    }
}
