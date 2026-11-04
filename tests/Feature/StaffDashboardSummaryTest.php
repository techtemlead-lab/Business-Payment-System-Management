<?php

namespace Tests\Feature;

use App\Domain\Orders\StaffDashboardSummary;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffDashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_home_includes_work_queue_and_two_week_trend(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, 'submitted', '500.00');
        $this->order($dealer, 'approved', '1200.00');

        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('data.admin_home.work_queue')
                ->has('data.admin_home.trend', StaffDashboardSummary::TrendDays)
                ->where('data.admin_home.month.sales', '1200.00')
                ->where('data.admin_home.month.orders', 1));
    }

    public function test_trend_puts_confirmed_sales_on_todays_bar_only(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, 'approved', '1200.00');
        $this->order($dealer, 'rejected', '999.00');

        $trend = app(StaffDashboardSummary::class)->for($owner)['trend'];

        $this->assertSame('1200.00', end($trend)['value']);
        $this->assertSame(1, end($trend)['orders']);
        $this->assertSame('0.00', $trend[0]['value']);
    }

    public function test_work_queue_only_lists_work_the_viewer_may_do(): void
    {
        $manager = User::factory()->staff('account_manager')->create();

        $keys = array_column(app(StaffDashboardSummary::class)->for($manager)['work_queue'], 'key');

        $this->assertContains('orders_to_review', $keys);
        $this->assertContains('applications_to_review', $keys);
        $this->assertNotContains('payments_to_check', $keys);
        $this->assertNotContains('withdrawals_to_review', $keys);
    }

    private function order(User $buyer, string $status, string $total): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }
}
