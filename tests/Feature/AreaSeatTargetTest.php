<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * MA-13: a seat's monthly target is checked on the server (a typed "20000" once saved as
 * 2,000,020,000), can be changed or closed afterwards, and the list says why a share is held.
 */
class AreaSeatTargetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private PlaceCode $division;

    private PlaceCode $place;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->division = PlaceCode::factory()->division()->create(['name' => 'Dhaka']);
        $this->place = PlaceCode::factory()->district($this->division)->create(['name' => 'Gazipur']);
        $home = PlaceCode::factory()->district($this->division)->create(['name' => 'Tangail']);
        $this->dealer = User::factory()->dealer()->inPlace($home)->create(['status' => 'active']);
    }

    /** @param array<string, mixed> $changes */
    private function add(array $changes = []): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/area-dealers', [
            'user_id' => $this->dealer->id,
            'level' => 'district',
            'place_code_id' => $this->place->id,
            'monthly_quota' => '20000',
            ...$changes,
        ]);
    }

    private function seat(): AreaDealerAssignment
    {
        $this->add()->assertSessionHasNoErrors();

        return AreaDealerAssignment::query()->where('place_code_id', $this->place->id)->firstOrFail();
    }

    private function staffWith(array $accounts): User
    {
        return User::factory()->staff('employee')->create([
            'profile' => ['permissions' => ['accounts'], 'action_permissions' => ['accounts' => $accounts]],
        ]);
    }

    public function test_a_target_that_is_ten_thousand_times_too_big_is_refused_with_a_clear_message(): void
    {
        // 20000 typed after a pre-filled 20000 used to save as 2000020000.
        $this->add(['monthly_quota' => '2000020000'])
            ->assertSessionHasErrors(['monthly_quota' => __('money_core.area_quota_max', ['max' => '1,00,00,000.00'])]);

        $this->assertDatabaseCount('area_dealer_assignments', 0);
    }

    public function test_a_normal_target_with_paisa_is_saved_exactly_and_the_edge_values_are_decided(): void
    {
        $this->add(['monthly_quota' => '10000000'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('area_dealer_assignments', ['place_code_id' => $this->place->id, 'monthly_quota' => '10000000.00']);

        $other = PlaceCode::factory()->district($this->division)->create(['name' => 'Narsingdi']);
        $this->add(['place_code_id' => $other->id, 'monthly_quota' => '10000000.01'])->assertSessionHasErrors('monthly_quota');
        $this->add(['place_code_id' => $other->id, 'monthly_quota' => '-5'])->assertSessionHasErrors(['monthly_quota' => __('money_core.area_quota_min')]);
        $this->add(['place_code_id' => $other->id, 'monthly_quota' => '100.123'])->assertSessionHasErrors(['monthly_quota' => __('money_core.area_quota_decimal')]);
        $this->add(['place_code_id' => $other->id, 'monthly_quota' => 'abc'])->assertSessionHasErrors(['monthly_quota' => __('money_core.area_quota_numeric')]);
        $this->add(['place_code_id' => $other->id, 'monthly_quota' => ''])->assertSessionHasErrors(['monthly_quota' => __('money_core.area_quota_required')]);

        $this->assertDatabaseCount('area_dealer_assignments', 1);
    }

    public function test_the_owner_can_change_a_seats_target_and_it_is_audited_and_told_to_the_holder(): void
    {
        $seat = $this->seat();

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/area-dealers/'.$seat->id, ['monthly_quota' => '35000.50'])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('35000.50', (string) session('success'));
        $fresh = $seat->fresh();
        $this->assertSame('35000.50', $fresh->monthly_quota);
        $this->assertTrue($fresh->is_active);
        $this->assertSame($this->dealer->id, $fresh->user_id);
        $this->assertSame($this->place->id, $fresh->place_code_id);
        $event = \DB::table('audit_events')->where('action', 'area_dealer.updated')->first();
        $this->assertNotNull($event);
        $this->assertSame((string) $this->dealer->id, $event->subject);
        $this->assertSame($this->owner->id, (int) $event->actor_id);
        $metadata = json_decode($event->metadata, true);
        $this->assertSame('20000.00', $metadata['monthly_quota_before']);
        $this->assertSame('35000.50', $metadata['monthly_quota_after']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $this->dealer->id, 'title' => __('money_core.notice_area_target_changed_title')]);
    }

    public function test_a_changed_target_obeys_the_same_limits_and_leaves_the_seat_alone(): void
    {
        $seat = $this->seat();
        $edit = fn (string $quota): TestResponse => $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/area-dealers/'.$seat->id, ['monthly_quota' => $quota]);

        $edit('2000020000')->assertSessionHasErrors('monthly_quota');
        $edit('-1')->assertSessionHasErrors('monthly_quota');
        $edit('12.345')->assertSessionHasErrors('monthly_quota');

        $this->assertSame('20000.00', $seat->fresh()->monthly_quota);
        $this->assertDatabaseMissing('audit_events', ['action' => 'area_dealer.updated']);
    }

    public function test_a_closed_seat_cannot_be_edited(): void
    {
        $seat = $this->seat();
        $seat->update(['is_active' => false, 'territory_key' => null]);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/area-dealers/'.$seat->id, ['monthly_quota' => '500'])->assertNotFound();

        $this->assertSame('20000.00', $seat->fresh()->monthly_quota);
    }

    public function test_closing_a_seat_keeps_the_row_and_frees_the_place_for_the_next_dealer(): void
    {
        $seat = $this->seat();

        $this->actingAs($this->owner)->onPortal('sadmin')->delete('/commands/area-dealers/'.$seat->id)->assertSessionHasNoErrors();

        $closed = $seat->fresh();
        $this->assertFalse($closed->is_active);
        $this->assertNull($closed->territory_key);
        $this->assertSame('20000.00', $closed->monthly_quota);
        $this->assertDatabaseHas('audit_events', ['action' => 'area_dealer.removed', 'subject' => (string) $this->dealer->id, 'actor_id' => $this->owner->id]);

        $next = User::factory()->dealer()->create(['status' => 'active']);
        $this->add(['user_id' => $next->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('area_dealer_assignments', ['user_id' => $next->id, 'place_code_id' => $this->place->id, 'is_active' => true]);

        $this->actingAs($this->owner)->onPortal('sadmin')->delete('/commands/area-dealers/'.$seat->id)->assertNotFound();
    }

    public function test_staff_who_may_only_view_cannot_change_or_close_a_seat(): void
    {
        $seat = $this->seat();
        $viewer = $this->staffWith(['view' => true]);

        $this->actingAs($viewer)->onPortal('manage')->post('/commands/area-dealers/'.$seat->id, ['monthly_quota' => '1'])->assertForbidden();
        $this->actingAs($viewer)->onPortal('manage')->delete('/commands/area-dealers/'.$seat->id)->assertForbidden();

        $this->assertTrue($seat->fresh()->is_active);
        $this->assertSame('20000.00', $seat->fresh()->monthly_quota);
    }

    public function test_staff_with_the_accounts_edit_right_may_change_and_close_a_seat(): void
    {
        $seat = $this->seat();
        $editor = $this->staffWith(['view' => true, 'edit' => true]);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/area-dealers/'.$seat->id, ['monthly_quota' => '30000'])->assertSessionHasNoErrors();
        $this->assertSame('30000.00', $seat->fresh()->monthly_quota);

        $this->actingAs($editor)->onPortal('manage')->delete('/commands/area-dealers/'.$seat->id)->assertSessionHasNoErrors();
        $this->assertFalse($seat->fresh()->is_active);
    }

    public function test_the_list_gives_each_seat_its_id_exact_totals_and_the_seat_texts(): void
    {
        $seat = $this->seat();
        $second = PlaceCode::factory()->district($this->division)->create(['name' => 'Narsingdi']);
        $this->add(['place_code_id' => $second->id, 'monthly_quota' => '10000.10'])->assertSessionHasNoErrors();
        $this->add(['place_code_id' => PlaceCode::factory()->district($this->division)->create()->id, 'monthly_quota' => '0.20'])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/area-dealers')->assertInertia(fn (Assert $page) => $page
            // The dealer's own place counts at the standard district target (40000) beside the three seats given here.
            ->where('data.area_summary.total_quota', '70000.30')
            ->where('data.area_text.area_seat_edit', __('money_core.area_seat_edit'))
            ->where('data.area_text.area_seat_held_quota', __('money_core.area_seat_held_quota'))
            ->where('data.assignments.data', fn ($rows): bool => collect($rows)->contains(fn (array $row): bool => $row['assignment_id'] === $seat->id && $row['held_amount'] === '0.00')));
    }

    public function test_a_seat_with_a_held_share_shows_the_amount_and_closing_it_keeps_the_money_record(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $rangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $holder = User::factory()->inPlace($rangpur)->create(['role' => 'dealer', 'status' => 'active']);
        $buyer = User::factory()->inPlace($rangpur)->create(['role' => 'dealer', 'status' => 'active']);
        $seat = AreaDealerAssignment::create([
            'user_id' => $holder->id, 'level' => 'division', 'place_code_id' => $rangpur->id, 'division' => 'Rangpur',
            'zone_name' => 'Rangpur', 'monthly_quota' => '1000000', 'is_active' => true,
            'territory_key' => hash('sha256', 'division|code:'.$rangpur->code),
        ]);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        app(CommissionPosting::class)->process($order);
        $held = CommissionAllocation::where('order_id', $order->id)->where('category', 'area_division')->firstOrFail();
        $this->assertSame('held', $held->status);
        $this->assertSame('quota', $held->metadata['hold_reason']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/area-dealers')->assertInertia(fn (Assert $page) => $page
            ->where('data.assignments.data', fn ($rows): bool => collect($rows)->contains(
                fn (array $row): bool => $row['assignment_id'] === $seat->id && $row['held_amount'] === number_format((float) $held->amount, 2, '.', '') && (float) $row['held_amount'] > 0,
            )));

        $this->actingAs($this->owner)->onPortal('sadmin')->delete('/commands/area-dealers/'.$seat->id)->assertSessionHasNoErrors();

        $this->assertFalse($seat->fresh()->is_active);
        $stillThere = $held->fresh();
        $this->assertSame('held', $stillThere->status);
        $this->assertSame($seat->id, $stillThere->metadata['assignment_id']);
        $this->assertSame($held->amount, $stillThere->amount);
    }
}
