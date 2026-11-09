<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\MixedOutcomeDecisions;
use App\Domain\Orders\OrderService;
use App\Models\CommissionAllocation;
use App\Models\DatedScheme;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MixedOutcomeDeliveredPartCommissionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $reviewer;

    private User $store;

    private User $dealer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->reviewer = User::factory()->staff('finance')->create(['status' => 'active']);
        $this->store = User::factory()->staff('inventory')->create(['status' => 'active']);
        $sponsor = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($sponsor->id, null);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($this->dealer->id, $sponsor->id);
        $this->product = Product::factory()->create(['active' => true, 'mrp' => '200.00', 'dealer_price' => '133.37', 'stock' => 100]);
    }

    public function test_delivered_part_posts_exactly_what_a_same_sized_order_posts(): void
    {
        $sameSized = $this->fullyDelivered(3);

        $mixed = $this->mixedOrder(3, 4);
        $this->decide($mixed, MixedOutcomeDecisions::DeliveredPart);

        $batch = DB::table('commission_batches')->where('order_id', $mixed->id)->first();
        $this->assertSame($this->money($sameSized), $this->money($mixed));
        $this->assertSame($this->rows($sameSized), $this->rows($mixed));
        $this->assertSame($this->journal($sameSized), $this->journal($mixed));
        $this->assertSame('400.11', (string) $batch->cash_basis);
        $this->assertSame('600.00', (string) $batch->mrp_basis);
        $this->assertJournalBalances($mixed);
        foreach (CommissionAllocation::query()->where('order_id', $mixed->id)->get() as $allocation) {
            $this->assertSame('400.11', (string) $allocation->cash_basis);
            $this->assertSame('delivered_part', $allocation->metadata['posting_scope']);
        }
        $this->assertSame([(int) $mixed->lines()->first()->id => 3], json_decode((string) $batch->policy, true)['delivered_part']['line_quantities']);
    }

    public function test_rounding_of_the_delivered_part_matches_to_the_paisa_across_many_sizes(): void
    {
        foreach ([1, 2, 5, 7, 11] as $delivered) {
            $sameSized = $this->fullyDelivered($delivered);
            $mixed = $this->mixedOrder($delivered, 3);

            $this->decide($mixed, MixedOutcomeDecisions::DeliveredPart);

            $this->assertSame($this->money($sameSized), $this->money($mixed), 'Delivered '.$delivered);
            $this->assertSame($this->rows($sameSized), $this->rows($mixed), 'Delivered '.$delivered);
        }
    }

    public function test_free_goods_in_the_delivered_parcel_earn_nothing(): void
    {
        $sameSized = $this->fullyDelivered(4);
        $free = Product::factory()->create(['active' => true, 'mrp' => '500.00', 'dealer_price' => '300.00', 'stock' => 100]);
        DatedScheme::query()->create(['title' => 'Free', 'starts_on' => now('Asia/Dhaka')->subDay()->toDateString(), 'ends_on' => now('Asia/Dhaka')->addDay()->toDateString(),
            'product_ids' => [$this->product->id], 'min_quantity' => 1, 'benefit_type' => 'free_goods', 'benefit_text' => 'Free', 'free_product_id' => $free->id, 'free_quantity' => 6, 'created_by' => $this->owner->id]);

        $mixed = $this->mixedOrder(4, 2);
        $this->decide($mixed, MixedOutcomeDecisions::DeliveredPart);

        $this->assertSame(1, $mixed->lines()->where('is_free', true)->count());
        $this->assertSame($this->money($sameSized), $this->money($mixed));
        $this->assertSame($this->rows($sameSized), $this->rows($mixed));
    }

    public function test_running_the_posting_again_changes_nothing(): void
    {
        $mixed = $this->mixedOrder(3, 4);
        $this->decide($mixed, MixedOutcomeDecisions::DeliveredPart);
        $wallets = DB::table('wallets')->orderBy('user_id')->get(['user_id', 'available', 'held'])->toArray();
        $rows = $this->rows($mixed);
        $journalEntries = DB::table('journal_entries')->count();

        $again = app(CommissionPosting::class)->processDeliveredPart($mixed->fresh(), $this->owner->id);
        app(CommissionPosting::class)->process($mixed->fresh(), $this->owner->id);

        $this->assertCount(count($rows), $again);
        $this->assertSame(1, DB::table('commission_batches')->where('order_id', $mixed->id)->count());
        $this->assertSame($rows, $this->rows($mixed));
        $this->assertEquals($wallets, DB::table('wallets')->orderBy('user_id')->get(['user_id', 'available', 'held'])->toArray());
        $this->assertSame($journalEntries, DB::table('journal_entries')->count());
    }

    public function test_a_second_decision_is_refused_and_posts_nothing_more(): void
    {
        $mixed = $this->mixedOrder(3, 4);
        $this->decide($mixed, MixedOutcomeDecisions::DeliveredPart);
        $rows = $this->rows($mixed);

        try {
            $this->decide($mixed, MixedOutcomeDecisions::NoCommission);
            $this->fail('A second decision must be refused.');
        } catch (ValidationException) {
            $this->assertSame($rows, $this->rows($mixed));
        }
        $this->assertSame(1, DB::table('order_events')->where('order_id', $mixed->id)->where('action', MixedOutcomeDecisions::Action)->count());
    }

    public function test_choosing_no_commission_pays_nobody_and_keeps_the_money_in_the_company_profit_fund(): void
    {
        $sameSized = $this->money($this->fullyDelivered(3));
        $mixed = $this->mixedOrder(3, 4);
        $walletsBefore = DB::table('wallets')->orderBy('user_id')->get(['user_id', 'available', 'held'])->toArray();

        $this->decide($mixed, MixedOutcomeDecisions::NoCommission);

        $batch = DB::table('commission_batches')->where('order_id', $mixed->id)->first();
        $this->assertTrue((bool) $batch->no_commission);
        $this->assertSame($sameSized['cash_basis'], (string) $batch->cash_basis);
        $this->assertSame($sameSized['company_share'], (string) $batch->company_share);
        $this->assertSame($sameSized['total_allocated'], (string) CommissionAllocation::query()->where('order_id', $mixed->id)->where('category', CommissionPosting::NoCommissionCategory)->value('amount'));
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'commissions.order.'.$mixed->id)->count());
        $this->assertEquals($walletsBefore, DB::table('wallets')->orderBy('user_id')->get(['user_id', 'available', 'held'])->toArray());
        $rows = $this->rows($mixed);
        app(CommissionPosting::class)->process($mixed->fresh(), $this->owner->id);
        app(CommissionPosting::class)->processDeliveredPart($mixed->fresh(), $this->owner->id);
        $this->assertSame($rows, $this->rows($mixed));
    }

    public function test_the_reason_is_required_and_the_decision_is_audited_and_the_dealer_told(): void
    {
        $mixed = $this->mixedOrder(3, 4);
        $url = '/commands/orders/'.$mixed->id.'/mixed-outcome-decision';

        $this->actingAs($this->reviewer)->onPortal('manage')->post($url, ['decision' => MixedOutcomeDecisions::DeliveredPart])->assertSessionHasErrors('reason');
        $this->assertSame(0, DB::table('commission_batches')->where('order_id', $mixed->id)->count());

        $this->actingAs($this->reviewer)->onPortal('manage')->post($url, ['decision' => MixedOutcomeDecisions::DeliveredPart, 'reason' => 'Dealer agreed on the phone'])->assertSessionHasNoErrors();

        $event = DB::table('order_events')->where('order_id', $mixed->id)->where('action', MixedOutcomeDecisions::Action)->first();
        $this->assertSame(['decision' => 'delivered_part', 'reason' => 'Dealer agreed on the phone', 'commission_posted' => true], json_decode($event->metadata, true));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->reviewer->id, 'action' => MixedOutcomeDecisions::Action, 'subject' => $mixed->id]);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->reviewer->id, 'action' => 'commissions.calculated', 'subject' => $mixed->id]);
        $locale = $this->dealer->preferredLocale();
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->dealer->id, 'title' => __('courier_more.notice_decision_title', ['number' => $mixed->number], $locale)]);
        $body = DB::table('account_notices')->where('user_id', $this->dealer->id)->where('title', __('courier_more.notice_decision_title', ['number' => $mixed->number], $locale))->value('body');
        $this->assertStringContainsString(__('schemes_commission.decision_delivered_part', [], $locale), (string) $body);
    }

    public function test_only_the_two_new_choices_are_accepted(): void
    {
        $mixed = $this->mixedOrder(3, 4);

        $this->actingAs($this->reviewer)->onPortal('manage')
            ->post('/commands/orders/'.$mixed->id.'/mixed-outcome-decision', ['decision' => 'whole_sale', 'reason' => 'Dealer agreed on the phone'])
            ->assertSessionHasErrors('decision');
    }

    public function test_refunding_a_delivered_part_order_is_capped_at_the_delivered_value_and_reverses_proportionally(): void
    {
        $mixed = $this->mixedOrder(3, 4);
        $this->decide($mixed, MixedOutcomeDecisions::DeliveredPart);
        $cashbackBefore = Money::cents((string) CommissionAllocation::query()->where('order_id', $mixed->id)->where('category', 'own_dealer_cashback')->value('amount'));

        try {
            app(CommissionService::class)->reverseOrderPartial($mixed->fresh(), '400.12', 'Too much', 'too-much', $this->owner->id);
            $this->fail('A refund above the delivered value must be refused.');
        } catch (ValidationException) {
            $this->assertSame('0.00', (string) DB::table('commission_batches')->where('order_id', $mixed->id)->value('refunded_amount'));
        }

        app(CommissionService::class)->reverseOrder($mixed->fresh(), 'Dealer sent the rest back', $this->owner->id);

        $batch = DB::table('commission_batches')->where('order_id', $mixed->id)->first();
        $this->assertSame((string) $batch->cash_basis, (string) $batch->refunded_amount);
        $cashback = CommissionAllocation::query()->where('order_id', $mixed->id)->where('category', 'own_dealer_cashback')->first();
        $this->assertSame('reversed', $cashback->status);
        $this->assertGreaterThan(0, $cashbackBefore);
        $this->assertSame(0, Money::cents((string) $cashback->amount));
    }

    public function test_an_order_with_goods_still_unsent_waits_for_them(): void
    {
        $order = $this->paidOrder(10);
        $line = $order->lines()->first();
        $this->sendAndFinish($order, $line->id, 3, 'delivered');
        $this->sendAndFinish($order, $line->id, 3, 'returned');

        $this->expectException(ValidationException::class);
        $this->decide($order, MixedOutcomeDecisions::DeliveredPart);
    }

    public function test_an_order_where_only_free_goods_arrived_cannot_post_the_delivered_part(): void
    {
        $free = Product::factory()->create(['active' => true, 'mrp' => '500.00', 'dealer_price' => '300.00', 'stock' => 100]);
        DatedScheme::query()->create(['title' => 'Free', 'starts_on' => now('Asia/Dhaka')->subDay()->toDateString(), 'ends_on' => now('Asia/Dhaka')->addDay()->toDateString(),
            'product_ids' => [$this->product->id], 'min_quantity' => 1, 'benefit_type' => 'free_goods', 'benefit_text' => 'Free', 'free_product_id' => $free->id, 'free_quantity' => 2, 'created_by' => $this->owner->id]);
        $order = $this->paidOrder(3);
        $paid = $order->lines()->where('is_free', false)->first();
        $freeLine = $order->lines()->where('is_free', true)->first();
        $this->sendAndFinish($order, $freeLine->id, 2, 'delivered');
        $this->sendAndFinish($order, $paid->id, 3, 'returned');

        try {
            $this->decide($order, MixedOutcomeDecisions::DeliveredPart);
            $this->fail('Nothing paid arrived, so nothing can post.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('schemes_commission.err_nothing_paid_delivered'), $exception->errors()['decision'][0]);
        }
        $this->assertSame(0, DB::table('order_events')->where('order_id', $order->id)->where('action', MixedOutcomeDecisions::Action)->count());

        $this->decide($order, MixedOutcomeDecisions::NoCommission);
        $this->assertSame(0, DB::table('commission_batches')->where('order_id', $order->id)->count());
    }

    public function test_an_unpaid_order_is_refused_rather_than_silently_skipped(): void
    {
        $mixed = $this->mixedOrder(3, 4);
        DB::table('orders')->where('id', $mixed->id)->update(['paid' => '1.00']);

        try {
            $this->decide($mixed->fresh(), MixedOutcomeDecisions::DeliveredPart);
            $this->fail('An unpaid order must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('schemes_commission.err_not_paid_in_full'), $exception->errors()['decision'][0]);
        }
        $this->assertSame(0, DB::table('order_events')->where('order_id', $mixed->id)->where('action', MixedOutcomeDecisions::Action)->count());
    }

    public function test_a_cancelled_order_cannot_post_the_delivered_part(): void
    {
        $mixed = $this->mixedOrder(3, 4);
        DB::table('orders')->where('id', $mixed->id)->update(['status' => 'cancelled']);

        try {
            $this->decide($mixed, MixedOutcomeDecisions::DeliveredPart);
            $this->fail('A cancelled order must be refused.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('commission_batches')->where('order_id', $mixed->id)->count());
        }
    }

    public function test_the_order_itself_keeps_its_full_total_and_paid_amount(): void
    {
        $mixed = $this->mixedOrder(3, 4);

        $this->decide($mixed, MixedOutcomeDecisions::DeliveredPart);

        $fresh = $mixed->fresh();
        $this->assertSame((string) $mixed->total, (string) $fresh->total);
        $this->assertSame((string) $mixed->paid, (string) $fresh->paid);
        $this->assertSame('dispatched', $fresh->status);
    }

    private function decide(Order $order, string $choice): void
    {
        app(MixedOutcomeDecisions::class)->decide($this->reviewer, $order->fresh(), $choice, 'Dealer agreed on the phone');
    }

    private function paidOrder(int $quantity): Order
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total, 'status' => 'approved']);

        return $order->fresh();
    }

    private function fullyDelivered(int $quantity): Order
    {
        $order = $this->paidOrder($quantity);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh(), $this->owner->id);

        return $order->fresh();
    }

    /** One parcel delivered and one received back, with every unit sent. */
    private function mixedOrder(int $delivered, int $returned): Order
    {
        $order = $this->paidOrder($delivered + $returned);
        foreach ($order->lines()->where('is_free', true)->get() as $free) {
            $this->sendAndFinish($order, $free->id, (int) $free->quantity, 'delivered');
        }
        $line = $order->lines()->where('is_free', false)->first();
        $this->sendAndFinish($order, $line->id, $delivered, 'delivered');
        $this->sendAndFinish($order, $line->id, $returned, 'returned');
        DB::table('orders')->where('id', $order->id)->update(['status' => 'dispatched']);

        return $order->fresh();
    }

    private function sendAndFinish(Order $order, int $lineId, int $quantity, string $status): void
    {
        DB::table('order_lines')->where('id', $lineId)->increment('dispatched', $quantity);
        DB::table('shipments')->insert(['order_id' => $order->id, 'courier' => 'Test', 'tracking' => 'T-'.Str::random(6), 'status' => $status, 'courier_status' => $status,
            'lines' => json_encode([['line_id' => $lineId, 'name' => 'Item', 'quantity' => $quantity, 'lots' => []]], JSON_THROW_ON_ERROR),
            'actor_id' => $this->store->id, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, string> */
    private function money(Order $order): array
    {
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        $this->assertNotNull($batch);

        return ['cash_basis' => (string) $batch->cash_basis, 'mrp_basis' => (string) $batch->mrp_basis, 'company_share' => (string) $batch->company_share,
            'total_allocated' => (string) $batch->total_allocated, 'unallocated' => (string) $batch->unallocated];
    }

    /** @return list<string> */
    private function rows(Order $order): array
    {
        return CommissionAllocation::query()->where('order_id', $order->id)->get()
            ->map(fn (CommissionAllocation $row): string => implode('|', [$row->category, (string) $row->user_id, (string) $row->amount, (string) $row->rate_percentage, $row->status]))
            ->sort()->values()->all();
    }

    /** @return list<string> */
    private function journal(Order $order): array
    {
        return DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.event_key', 'commissions.order.'.$order->id)->get(['account', 'user_id', 'debit', 'credit'])
            ->map(fn (object $line): string => implode('|', [$line->account, (string) $line->user_id, (string) $line->debit, (string) $line->credit]))
            ->sort()->values()->all();
    }

    private function assertJournalBalances(Order $order): void
    {
        $entry = DB::table('journal_entries')->where('event_key', 'commissions.order.'.$order->id)->value('id');
        $this->assertNotNull($entry);
        $lines = DB::table('journal_lines')->where('entry_id', $entry)->get();
        $debit = $lines->sum(fn (object $line): int => Money::cents((string) $line->debit));
        $this->assertGreaterThan(0, $debit);
        $this->assertSame($debit, $lines->sum(fn (object $line): int => Money::cents((string) $line->credit)));
    }
}
