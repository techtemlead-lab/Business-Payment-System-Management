<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UplineReplacementOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_order_page_offers_replacements_for_uplines_who_can_no_longer_earn(): void
    {
        $owner = User::factory()->staff()->create();
        $suspended = User::factory()->dealer()->suspended()->create(['name' => 'Suspended Sponsor']);
        $active = User::factory()->dealer()->create(['name' => 'Active Sponsor']);
        $formerPartner = User::factory()->staff('employee')->create(['name' => 'Now Staff']);
        $buyer = User::factory()->subDealer()->create(['name' => 'Buyer Shop']);
        $order = $this->order($buyer, 'submitted', [$suspended->id, $active->id, $formerPartner->id, $suspended->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($owner)->onPortal('sadmin')->get('/orders/'.$order->id);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('OrderDetail')
            ->where('replacementSlots', [
                ['generation' => 1, 'name' => 'Suspended Sponsor'],
                ['generation' => 3, 'name' => 'Now Staff'],
            ])
            ->where('replacementCandidates', fn ($candidates) => collect($candidates)->pluck('name')->sort()->values()->all() === ['Active Sponsor']));
        // The three upline generations load in one query instead of one lookup each.
        $this->assertSame(1, $queries->filter(fn (string $sql): bool => str_starts_with($sql, 'select * from "users" where "id" in'))->count());
        $this->assertSame(0, $queries->filter(fn (string $sql): bool => str_starts_with($sql, 'select * from "users" where "users"."id" = ?'))->count());
    }

    public function test_replacement_search_filters_candidates_by_name_or_code(): void
    {
        $owner = User::factory()->staff()->create();
        $suspended = User::factory()->dealer()->suspended()->create();
        User::factory()->dealer()->create(['name' => 'Rahim Traders', 'dealer_code' => 'RT-100']);
        User::factory()->dealer()->create(['name' => 'Karim Store', 'dealer_code' => 'KS-200']);
        $order = $this->order(User::factory()->dealer()->create(['name' => 'Buyer']), 'held', [$suspended->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/orders/'.$order->id.'?replacement_q=ks-2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('replacementCandidates', fn ($candidates) => collect($candidates)->pluck('name')->all() === ['Karim Store']));
    }

    public function test_no_replacements_once_the_order_is_approved_or_every_upline_is_active(): void
    {
        $owner = User::factory()->staff()->create();
        $suspended = User::factory()->dealer()->suspended()->create();
        $active = User::factory()->dealer()->create();
        $buyer = User::factory()->dealer()->create();

        foreach ([$this->order($buyer, 'approved', [$suspended->id]), $this->order($buyer, 'submitted', [$active->id])] as $order) {
            $this->actingAs($owner)->onPortal('sadmin')->get('/orders/'.$order->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('replacementSlots', [])
                    ->where('replacementCandidates', []));
        }
    }

    /**
     * @param  list<int>  $uplines
     */
    private function order(User $buyer, string $status, array $uplines): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => '100.00',
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
            'commission_policy_snapshot' => ['uplines' => $uplines],
        ]);
    }
}
