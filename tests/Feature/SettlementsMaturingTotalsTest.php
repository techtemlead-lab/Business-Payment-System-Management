<?php

namespace Tests\Feature;

use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettlementsMaturingTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_settlements_page_totals_maturing_commission_without_loading_every_row(): void
    {
        $this->withoutVite();
        $partner = User::factory()->dealer()->create();
        $order = Order::query()->create(['number' => 'MN-TEST-1', 'user_id' => $partner->id, 'total' => '1000.00', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)]);
        foreach (['100.50', '200.25', '0.25'] as $amount) {
            CommissionAllocation::query()->create(['order_id' => $order->id, 'user_id' => $partner->id, 'category' => 'dealer_margin', 'rate_percentage' => 10, 'cash_basis' => '1000.00', 'amount' => $amount, 'status' => 'maturing']);
        }
        DB::enableQueryLog();

        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/settlements')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.settlement_state.maturing_count', 3)
                ->where('data.settlement_state.maturing_amount', 301));

        $loadedEveryRow = collect(DB::getQueryLog())->contains(fn (array $query): bool => str_starts_with($query['query'], 'select * from "commission_allocations"')
            && str_contains($query['query'], '"status" = ?') && in_array('maturing', $query['bindings'], true));
        $this->assertFalse($loadedEveryRow, 'The page should ask the database for the total, not load every maturing row.');
    }
}
