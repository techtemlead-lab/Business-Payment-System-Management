<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerSalaryNotes;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * MA-03 (partner side), MA-11 and MA-20: held commission is shown to the partner as held and never
 * as received, the salary line explains itself, and the team share has its new name.
 */
class PartnerHeldAndSalaryNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_my_money_lists_held_commission_with_the_reason_and_does_not_count_it_as_received(): void
    {
        $partner = User::factory()->dealer()->create();
        $buyer = User::factory()->dealer()->create();
        $suspendedOrder = $this->order($buyer, '1000.00');
        $quotaOrder = $this->order($buyer, '2000.00');
        $this->allocation($suspendedOrder, $partner, 'direct_referral', '750000.00', 'held');
        $this->allocation($quotaOrder, $partner, 'area_division', '300.00', 'held', ['hold_reason' => 'quota', 'period_month' => '2026-11']);

        // The target-held area share is listed as pending (see PartnerPendingMoneyTest), not in the held list.
        $this->actingAs($partner)->onPortal('dealer')->get('/my-money')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('held_commission.total', '750000.00')
            ->where('held_commission.count', 1)
            ->where('held_commission.lines', function ($lines) use ($suspendedOrder): bool {
                $byOrder = collect($lines)->keyBy('order_number');
                $this->assertSame('750000.00', $byOrder[$suspendedOrder->number]['amount']);
                $this->assertSame('inactive', $byOrder[$suspendedOrder->number]['reason']);

                return true;
            })
            ->where('pending.total', '300.00')
            ->where('summary.lifetime.earned', '0.00')
            ->where('summary.available', '0.00')
            ->where('cards', []));
    }

    public function test_the_partner_home_shows_the_held_commission_total_and_its_lines(): void
    {
        $partner = User::factory()->dealer()->create();
        $buyer = User::factory()->dealer()->create();
        foreach (['100.00', '200.00', '300.00', '400.00'] as $amount) {
            $this->allocation($this->order($buyer, '1000.00'), $partner, 'direct_referral', $amount, 'held');
        }
        $this->allocation($this->order($buyer, '1000.00'), $partner, 'direct_referral', '50.00', 'available');

        $held = app(PartnerDashboardHome::class)->for($partner)['wallet']['commission_held'];

        $this->assertSame('1000.00', $held['total']);
        $this->assertSame(4, $held['count']);
        $this->assertCount(3, $held['lines']);
    }

    public function test_a_partner_with_nothing_held_sees_no_held_lines(): void
    {
        $partner = User::factory()->dealer()->create();

        $this->actingAs($partner)->onPortal('dealer')->get('/my-money')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('held_commission.total', '0.00')->where('held_commission.lines', [])->where('salary_notes', []));
    }

    public function test_the_team_tree_shows_held_money_as_held_and_not_as_received(): void
    {
        $root = User::factory()->dealer()->create(['name' => 'Root Dealer']);
        $child = User::factory()->dealer()->create(['name' => 'Child Dealer', 'sponsor_id' => $root->id]);
        $grandchild = User::factory()->dealer()->create(['name' => 'Grandchild Dealer', 'sponsor_id' => $child->id]);
        ReferralTreePath::attachUser($root->id, null);
        ReferralTreePath::attachUser($child->id, $root->id);
        ReferralTreePath::attachUser($grandchild->id, $child->id);
        $order = $this->order($grandchild, '1000.00', 'delivered');
        $this->allocation($order, $child, 'direct_referral', '750000.00', 'held');
        $this->allocation($order, $child, 'direct_referral', '40.00', 'available');
        $this->allocation($order, $root, 'd2d_gen2', '20.00', 'held');
        $this->allocation($order, $root, 'd2d_gen2', '5.00', 'settled');

        $this->actingAs($root)->onPortal('dealer')->get('/network')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.dealers', function ($dealers) use ($grandchild): bool {
                $node = collect($dealers)->keyBy('id')[$grandchild->id];
                $this->assertSame('40.00', $node['sponsor_earned'], 'Held money is not received by the referrer.');
                $this->assertSame('750000.00', $node['sponsor_held']);
                $this->assertSame('5.00', $node['viewer_earned']);
                $this->assertSame('20.00', $node['viewer_held']);
                $this->assertSame([['category' => 'd2d_gen2', 'amount' => '5.00']], $node['viewer_earned_by_type']);

                return true;
            }));
    }

    public function test_the_salary_line_says_the_pool_the_sharers_and_the_rule_for_a_closed_month(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $inPlace = $this->unionDealerFactory();
        [$sharerA, $sharerB, $small] = [$inPlace('Chaitrakul'), $inPlace('Kabilpur'), $inPlace('Tukuria')];
        $this->purchase($sharerA, '40000.00', '80000.00');
        $this->purchase($sharerB, '30000.00', '60000.00');
        $this->purchase($small, '5000.00', '10000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));

        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        // One pool for the whole country: 2% of ৳75,000, shared by the two dealers who met their own target (৳30,000).
        $country = $result['divisions'][0];
        $note = app(PartnerSalaryNotes::class)->for($sharerA)[0];
        $this->assertSame('paid', $note['state']);
        $this->assertSame('2026-09', $note['month']);
        $this->assertSame($country['pool'], $note['pool']);
        $this->assertSame('1500.00', $note['pool']);
        $this->assertSame($country['qualifiers'], $note['sharers']);
        $this->assertSame(2, $note['sharers']);
        $this->assertSame('750.00', $note['amount']);
        $this->assertSame('30000.00', $note['min_volume']);

        $this->actingAs($sharerA)->onPortal('dealer')->get('/my-money')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('salary_notes.0.state', 'paid')->where('salary_notes.0.sharers', 2)->where('salary_notes.0.pool', '1500.00'));
    }

    public function test_a_dealer_with_no_salary_for_a_closed_month_gets_the_one_line_no_purchase_of_the_minimum(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $inPlace = $this->unionDealerFactory();
        [$sharer, $small] = [$inPlace('Chaitrakul'), $inPlace('Kabilpur')];
        $this->purchase($sharer, '30000.00', '60000.00');
        $this->purchase($small, '5000.00', '10000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));
        app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        $notes = app(PartnerSalaryNotes::class)->for($small);

        $this->assertCount(1, $notes);
        $this->assertSame('none_low_volume', $notes[0]['state']);
        $this->assertSame('30000.00', $notes[0]['min_volume']);
        $this->assertNull($notes[0]['amount']);
        $this->assertSame([], app(PartnerSalaryNotes::class)->for(User::factory()->subDealer()->create()), 'A sub-dealer has no division pool.');
    }

    public function test_the_team_share_label_is_used_in_both_languages_and_no_dealer_to_dealer_wording_is_left(): void
    {
        $this->assertSame('টিমের আয় (২য় ও ৩য় ধাপ)', __('portal.rule_name_d2d_multitier', [], 'bn'));
        $this->assertSame('Team income (2nd and 3rd step)', __('portal.rule_name_d2d_multitier', [], 'en'));
        foreach (['bn', 'en'] as $locale) {
            $words = require lang_path($locale.'/portal.php');
            foreach ($words as $key => $word) {
                if (str_contains((string) $key, 'd2d')) {
                    $this->assertDoesNotMatchRegularExpression('/dealer[- ]to[- ]dealer|ডিলার-টু-ডিলার|ডিলার থেকে ডিলার/iu', (string) $word, "{$locale} {$key}");
                }
            }
        }
        App::setLocale('en');
        $this->seed(CommissionRuleSeeder::class);
        $this->assertSame('Team income (2nd and 3rd step)', app(CommissionPolicy::class)->snapshot(false)['rules']['d2d_multitier']['name']);
    }

    public function test_every_new_word_is_there_in_both_languages(): void
    {
        $bangla = require lang_path('bn/money_core.php');
        $english = require lang_path('en/money_core.php');

        foreach (['held_heading', 'held_reason_inactive', 'held_reason_quota', 'net_referrer_held', 'net_you_held', 'salary_paid', 'salary_rule', 'salary_none_low'] as $key) {
            $this->assertNotSame('', trim((string) ($bangla[$key] ?? '')), "bn {$key}");
            $this->assertNotSame('', trim((string) ($english[$key] ?? '')), "en {$key}");
        }
    }

    /** Makes dealers seated in a union of one upazila: their one monthly target is ৳30,000. */
    private function unionDealerFactory(): \Closure
    {
        $upazila = PlaceCode::factory()->upazila(PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Rangpur']))->create(['name' => 'Rangpur']))->create(['name' => 'Pirganj']);

        return fn (string $union): User => User::factory()->inPlace(PlaceCode::factory()->union($upazila)->create(['name' => $union]))->create();
    }

    private function purchase(User $buyer, string $cash, string $mrp): Order
    {
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);

        return $order;
    }

    private function order(User $buyer, string $total, string $status = 'submitted'): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => $total,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function allocation(Order $order, User $earner, string $category, string $amount, string $status, array $metadata = []): void
    {
        DB::table('commission_allocations')->insert([
            'order_id' => $order->id,
            'user_id' => $earner->id,
            'category' => $category,
            'rate_percentage' => '5.00',
            'cash_basis' => $order->total,
            'amount' => $amount,
            'status' => $status,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
