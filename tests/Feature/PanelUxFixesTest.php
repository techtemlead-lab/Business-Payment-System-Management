<?php

namespace Tests\Feature;

use App\Domain\Finance\TreasuryWork;
use App\Domain\Orders\OrderListFilter;
use App\Models\Order;
use App\Models\PaymentClaim;
use App\Models\User;
use App\Support\Audit;
use App\Support\AuditSubjectNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Panel fixes from the 2026-11-18 whole-company test: one pending-payment count, last-30-days defaults, readable work log. */
class PanelUxFixesTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $buyer, string $status = 'submitted', string $total = '500.00'): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ])->fresh();
    }

    private function claim(Order $order, string $channel, string $status): void
    {
        PaymentClaim::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'amount' => '100.00',
            'channel' => $channel,
            'reference' => 'REF-'.Str::random(8),
            'status' => $status,
        ]);
    }

    public function test_the_dashboard_the_payments_page_and_the_money_badge_count_the_same_pending_payments(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $order = $this->order($dealer);
        $this->claim($order, 'bKash', 'pending');
        $this->claim($order, 'Office', 'pending');
        // Paid from the partner's own balance: checked by the system, never waits for a person.
        $this->claim($order, 'Account', 'pending');
        $this->claim($order, 'Bank', 'verified');

        $this->actingAs($owner)->onPortal('sadmin')->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.admin_home.work_queue', fn ($queue): bool => collect($queue)->firstWhere('key', 'payments_to_check')['count'] === 2));

        $this->actingAs($owner)->onPortal('sadmin')->get('/payments')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.claim_status_counts.pending', 2));

        $this->assertSame(2, app(TreasuryWork::class)->waitingCount($owner, 'sadmin'));
    }

    public function test_the_money_event_history_opens_on_the_last_thirty_days(): void
    {
        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/treasury/events')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.state.period', 'last30')
                ->where('table.extra_periods', ['last30']));
    }

    public function test_the_last_thirty_days_view_reaches_back_twenty_nine_days(): void
    {
        $this->travelTo('2026-11-20 12:00:00');

        $range = OrderListFilter::createdRange('last30', null, null);

        $this->assertSame('2026-10-22', $range['from']->setTimezone('Asia/Dhaka')->toDateString());
        $this->assertSame('2026-11-20', $range['until']->setTimezone('Asia/Dhaka')->toDateString());
    }

    public function test_the_settings_page_names_every_link_in_words(): void
    {
        $owner = User::factory()->staff()->create();

        $page = $this->actingAs($owner)->onPortal('sadmin')->get('/settings')->assertOk();

        $this->assertStringNotContainsString('office_menu.settings_link_', $page->getContent());
        foreach (['bn', 'en'] as $locale) {
            $this->assertNotSame('office_menu.settings_link_errors', __('office_menu.settings_link_errors', [], $locale));
        }
    }

    public function test_the_work_log_shows_an_order_number_and_a_name_not_an_id(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim Uddin']);
        $order = $this->order($dealer);

        $names = AuditSubjectNames::for([(string) $order->id, 'user:'.$dealer->id, 'partner_agreement_version:1', 'unknown-subject']);

        $this->assertSame($order->number, $names[(string) $order->id]);
        $this->assertSame('Rahim Uddin', $names['user:'.$dealer->id]);
        $this->assertArrayHasKey('partner_agreement_version:1', $names);
        $this->assertArrayNotHasKey('unknown-subject', $names);

        Audit::record($owner->id, 'order.approved', (string) $order->id);
        $this->actingAs($owner)->onPortal('sadmin')->get('/audit')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.work_log_table.rows.data.0.subject', $order->number));
    }

    public function test_the_held_commission_list_shows_the_order_number_and_no_ids(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/operations/commission-holds')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tables', fn ($tables): bool => collect($tables)->pluck('key')->contains('held_allocations')));
    }
}
