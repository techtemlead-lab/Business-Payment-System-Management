<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function member(?User $sponsor, array $attributes = []): User
    {
        $user = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $sponsor?->id, ...$attributes]);
        ReferralTreePath::attachUser($user->id, $sponsor?->id);

        return $user;
    }

    /**
     * A delivered order with the commission batch delivery writes for that month: the team's
     * "this month" figures follow the delivery month, the same month the salary target uses.
     */
    private function delivered(User $buyer, string $total, $createdAt): void
    {
        $order = Order::create([
            'number' => 'ORD-TEAM-'.Str::random(6),
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'channel' => 'dealer',
            'currency' => 'BDT',
            'subtotal' => $total,
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => $total,
            'paid' => $total,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        $order->forceFill(['created_at' => $createdAt])->save();
        DB::table('commission_batches')->insert([
            'order_id' => $order->id,
            'account_type' => 'dealer',
            'period_month' => $createdAt->copy()->setTimezone(config('madina.business_timezone'))->format('Y-m'),
            'cash_basis' => $total,
            'mrp_basis' => $total,
            'company_share' => $total,
            'total_allocated' => '0.00',
            'unallocated' => '0.00',
            'refunded_amount' => '0.00',
            'policy' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_dealer_sees_team_size_new_joiners_buyers_and_quiet_members(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(12, 0));
        $dealer = $this->member(null, ['created_at' => now()->subYear()]);
        $active = $this->member($dealer, ['created_at' => now()->subMonths(6)]);
        $quiet = $this->member($dealer, ['created_at' => now()->subMonths(6)]);
        $this->member($active, ['created_at' => now()->subDays(3)]);
        $this->delivered($active, '1500.00', now()->subDays(2));
        $this->delivered($quiet, '800.00', now()->subDays(90));
        $this->delivered($dealer, '9999.00', now()->subDay());
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        $summary = $this->actingAs($dealer)->onPortal('dealer')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get('/network')->assertOk()->json('props.data.team_summary');

        $this->assertSame([
            'members' => 3,
            'direct' => 2,
            'joined_this_month' => 1,
            'bought_this_month' => 1,
            'quiet_60_days' => 1,
            'team_sales_month' => '1500.00',
        ], $summary);
    }
}
