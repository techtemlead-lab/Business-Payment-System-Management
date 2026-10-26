<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerCircularsAndSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_sees_published_circulars_and_can_send_support(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01710000099',
            'dealer_code' => 'MNHC-CIRC',
        ]);
        DB::table('content_pages')->insert([
            'slug' => 'circular-training',
            'title' => 'April field training',
            'body' => 'Join the Pirganj training on Saturday morning.',
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('content_pages')->insert([
            'slug' => 'circular-price',
            'title' => 'Draft price note',
            'body' => 'Should stay hidden.',
            'published' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/circulars')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('section', 'circulars')
                ->has('data.circulars', 1)
                ->where('data.circulars.0.title', 'April field training'));

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/partner-support', [
                'topic' => 'payout_question',
                'message' => 'When will March salary settle into my wallet?',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('enquiries', [
            'name' => $dealer->name,
            'phone' => '01710000099',
            'topic' => 'payout_question',
            'status' => 'new',
        ]);
    }

    public function test_full_report_date_filter_limits_orders(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $product = Product::factory()->create([
            'mrp' => '200.00',
            'dealer_price' => '100.00',
            'active' => true,
            'stock' => 500,
        ]);
        $inside = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $inside->forceFill([
            'status' => 'delivered',
            'paid' => $inside->total,
            'created_at' => '2026-03-10 08:00:00',
            'updated_at' => '2026-03-10 08:00:00',
        ])->save();

        $outside = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $outside->forceFill([
            'status' => 'delivered',
            'paid' => $outside->total,
            'created_at' => '2026-01-05 08:00:00',
            'updated_at' => '2026-01-05 08:00:00',
        ])->save();

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/full-report?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.full_report.summary.orders_count', 1)
                ->where('data.full_report.orders.0.id', $inside->id)
                ->where('data.full_report.filters.from', '2026-03-01')
                ->where('data.full_report.filters.to', '2026-03-31'));
    }
}
