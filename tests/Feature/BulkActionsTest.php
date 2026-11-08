<?php

namespace Tests\Feature;

use App\Domain\Lists\BulkActions;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bulk actions are a short fixed list of read-only actions. Money is never one of them.
 */
class BulkActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_four_safe_actions_exist(): void
    {
        $this->assertSame(['orders-export', 'order-slips', 'partners-export', 'notices-read'], BulkActions::Allowed);
    }

    public function test_money_operations_are_refused_even_for_the_owner(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $order = $this->orderFor($dealer, null, 'ORD-MONEY-1');
        $withdrawalId = (string) Str::uuid();
        DB::table('withdrawals')->insert(['id' => $withdrawalId, 'user_id' => $dealer->id, 'amount' => '500.00', 'status' => 'requested', 'channel' => 'bKash', 'destination' => encrypt('01711111111'), 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

        foreach (['approve-withdrawals', 'pay-withdrawals', 'approve-payouts', 'verify-payments', 'approve-refunds', 'refund-orders', 'release-commissions', 'settle-commissions', 'approve-orders'] as $action) {
            $this->actingAs($owner)->onPortal('sadmin')
                ->postJson('/commands/bulk/'.$action, ['ids' => [$withdrawalId, (string) $order->id]])
                ->assertForbidden();
        }

        $this->assertSame('requested', DB::table('withdrawals')->where('id', $withdrawalId)->value('status'));
        $this->assertSame('submitted', $order->fresh()->status);
    }

    public function test_exporting_selected_orders_stays_inside_the_branch(): void
    {
        $dhaka = CompanyBranch::factory()->create(['code' => 'DHK-01']);
        $rajshahi = CompanyBranch::factory()->create(['code' => 'RAJ-01']);
        $dealer = User::factory()->dealer()->create();
        $inside = $this->orderFor($dealer, $dhaka, 'ORD-IN-1');
        $outside = $this->orderFor($dealer, $rajshahi, 'ORD-OUT-1');
        $staff = $this->employee(['orders']);
        BranchStaffAssignment::query()->create(['user_id' => $staff->id, 'branch_id' => $dhaka->id, 'is_primary' => true]);

        $file = $this->actingAs($staff)->onPortal('manage')
            ->post('/commands/bulk/orders-export', ['ids' => [(string) $inside->id, (string) $outside->id]])
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('ORD-IN-1', $file);
        $this->assertStringNotContainsString('ORD-OUT-1', $file);
    }

    public function test_exported_phones_are_masked_unless_the_person_may_see_full_contacts(): void
    {
        $partner = User::factory()->dealer()->create(['phone' => '01733334444']);
        $order = $this->orderFor($partner, null, 'ORD-PHONE-1');
        $ordersOnly = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['orders', 'finance']]]);

        $masked = $this->actingAs($ordersOnly)->onPortal('manage')
            ->post('/commands/bulk/orders-export', ['ids' => [(string) $order->id]])->assertOk()->streamedContent();
        $full = $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')
            ->post('/commands/bulk/orders-export', ['ids' => [(string) $order->id]])->assertOk()->streamedContent();

        $this->assertStringContainsString('••••4444', $masked);
        $this->assertStringNotContainsString('01733334444', $masked);
        $this->assertStringContainsString('01733334444', $full);
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $partner = User::factory()->dealer()->create();
        $order = $this->orderFor($partner, null, 'ORD-PERM-1');
        $catalogOnly = $this->employee(['catalog']);
        $ordersOnly = $this->employee(['orders']);

        $this->actingAs($catalogOnly)->onPortal('manage')->postJson('/commands/bulk/orders-export', ['ids' => [(string) $order->id]])->assertForbidden();
        $this->actingAs($catalogOnly)->onPortal('manage')->postJson('/commands/bulk/order-slips', ['ids' => [(string) $order->id]])->assertForbidden();
        $this->actingAs($ordersOnly)->onPortal('manage')->postJson('/commands/bulk/partners-export', ['ids' => [$partner->id]])->assertForbidden();
    }

    public function test_partners_cannot_export_or_print_from_the_office_lists(): void
    {
        $partner = User::factory()->dealer()->create();
        $order = $this->orderFor($partner, null, 'ORD-PARTNER-1');

        $this->actingAs($partner)->onPortal('dealer')->postJson('/commands/bulk/orders-export', ['ids' => [(string) $order->id]])->assertForbidden();
        $this->actingAs($partner)->onPortal('dealer')->postJson('/commands/bulk/order-slips', ['ids' => [(string) $order->id]])->assertForbidden();
        $this->actingAs($partner)->onPortal('dealer')->postJson('/commands/bulk/partners-export', ['ids' => [$partner->id]])->assertForbidden();
    }

    public function test_selected_partners_export_leaves_out_staff_accounts(): void
    {
        $owner = User::factory()->staff()->create();
        $partner = User::factory()->dealer()->create(['name' => 'Chosen Partner']);
        $staff = User::factory()->staff('employee')->create(['name' => 'Office Person']);

        $file = $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/bulk/partners-export', ['ids' => [$partner->id, $staff->id]])
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Chosen Partner', $file);
        $this->assertStringNotContainsString('Office Person', $file);
    }

    public function test_order_slips_show_the_items_and_a_masked_phone(): void
    {
        $owner = User::factory()->staff()->create();
        $partner = User::factory()->dealer()->create(['phone' => '01755551234']);
        $order = $this->orderFor($partner, null, 'ORD-SLIP-1');
        $product = Product::factory()->create();
        DB::table('order_lines')->insert(['order_id' => $order->id, 'product_id' => $product->id, 'name' => 'Slip Tonic', 'sku' => 'SKU-SLIP', 'quantity' => 3, 'dispatched' => 0, 'mrp' => '10.00', 'unit_price' => '10.00', 'total' => '30.00', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/bulk/order-slips', ['ids' => [(string) $order->id]])
            ->assertOk();

        $response->assertSee('ORD-SLIP-1')->assertSee('Slip Tonic')->assertSee('017****1234');
        $response->assertDontSee('01755551234');
    }

    public function test_notices_are_marked_read_only_for_their_owner(): void
    {
        $partner = User::factory()->dealer()->create();
        $other = User::factory()->dealer()->create();
        $mine = $this->noticeFor($partner);
        $alsoMine = $this->noticeFor($partner);
        $theirs = $this->noticeFor($other);

        $this->actingAs($partner)->onPortal('dealer')
            ->postJson('/commands/bulk/notices-read', ['ids' => [$mine, $theirs]])
            ->assertOk()
            ->assertJsonPath('data.unread', 1);

        $this->assertNotNull(DB::table('account_notices')->where('id', $mine)->value('read_at'));
        $this->assertNull(DB::table('account_notices')->where('id', $alsoMine)->value('read_at'));
        $this->assertNull(DB::table('account_notices')->where('id', $theirs)->value('read_at'));
    }

    public function test_too_many_rows_are_refused(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')
            ->postJson('/commands/bulk/notices-read', ['ids' => range(1, BulkActions::MaxItems + 1)])
            ->assertUnprocessable();
    }

    /** @param list<string> $permissions */
    private function employee(array $permissions): User
    {
        return User::factory()->staff('employee')->create(['profile' => ['permissions' => $permissions]]);
    }

    private function noticeFor(User $user): int
    {
        return (int) DB::table('account_notices')->insertGetId(['user_id' => $user->id, 'title' => 'Hello', 'body' => 'Body', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function orderFor(User $buyer, ?CompanyBranch $branch, string $number): Order
    {
        return Order::create([
            'number' => $number,
            'user_id' => $buyer->id,
            'status' => 'submitted',
            'total' => '100.00',
            'fulfillment_branch_id' => $branch?->id,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }
}
