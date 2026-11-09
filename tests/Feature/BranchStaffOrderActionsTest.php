<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Branch staff cannot open another branch's order, so they must not act on it either.
 * Staff with stock, accounts or finance rights work across all branches and are not limited.
 */
class BranchStaffOrderActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $reviewer;

    private CompanyBranch $ownBranch;

    private CompanyBranch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $settings = app(IntegrationSettings::class);
        foreach (['steadfast', 'redx'] as $courier) {
            $settings->saveProvider($this->owner, 'couriers', $courier, [
                'enabled' => true,
                'base_url' => 'https://courier.test/api/v1',
                'api_key' => 'key',
                'api_secret' => 'secret',
            ], $settings->snapshot()['version'], 'Courier test');
        }
        $this->ownBranch = CompanyBranch::factory()->create();
        $this->otherBranch = CompanyBranch::factory()->create();
        $this->reviewer = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['orders', 'order_review']]]);
        BranchStaffAssignment::query()->create(['branch_id' => $this->ownBranch->id, 'user_id' => $this->reviewer->id, 'duty' => 'storekeeper', 'is_primary' => true]);
    }

    public function test_the_reviewer_cannot_open_the_other_branch_order(): void
    {
        $order = $this->order('approved', $this->otherBranch);

        $this->actingAs($this->reviewer)->onPortal('manage')->get('/orders/'.$order->id)->assertNotFound();
    }

    public function test_the_courier_of_another_branch_order_cannot_be_changed(): void
    {
        $order = $this->order('approved', $this->otherBranch);

        $this->actingAs($this->reviewer)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/courier', ['courier' => 'redx'])
            ->assertForbidden();

        $this->assertSame('steadfast', $order->fresh()->courier_choice);
    }

    public function test_the_courier_of_an_order_at_the_reviewers_own_branch_can_be_changed(): void
    {
        $order = $this->order('approved', $this->ownBranch);

        $this->actingAs($this->reviewer)->onPortal('manage')
            ->from('/orders/'.$order->id)
            ->post('/commands/orders/'.$order->id.'/courier', ['courier' => 'redx'])
            ->assertRedirect();

        $this->assertSame('redx', $order->fresh()->courier_choice);
    }

    public function test_a_refused_booking_at_another_branch_cannot_be_sent_again(): void
    {
        $order = $this->order('dispatched', $this->otherBranch);
        $shipment = DB::table('shipments')->insertGetId([
            'order_id' => $order->id, 'branch_id' => $this->otherBranch->id, 'courier' => 'Steadfast', 'courier_provider' => 'steadfast',
            'courier_booking_status' => 'failed', 'tracking' => '', 'lines' => '[]', 'actor_id' => $this->owner->id,
            'status' => 'dispatched', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->reviewer)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/shipments/'.$shipment.'/courier-retry')
            ->assertForbidden();

        $this->assertSame('failed', DB::table('shipments')->where('id', $shipment)->value('courier_booking_status'));
    }

    public function test_an_order_at_another_branch_cannot_be_approved(): void
    {
        $order = $this->order('submitted', $this->otherBranch);

        $this->actingAs($this->reviewer)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Looks correct to me'])
            ->assertForbidden();

        $this->assertSame('submitted', $order->fresh()->status);
    }

    public function test_a_mixed_outcome_at_another_branch_cannot_be_decided(): void
    {
        $order = $this->order('dispatched', $this->otherBranch);

        $this->actingAs($this->reviewer)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/mixed-outcome-decision', ['decision' => 'no_commission', 'reason' => 'Discussed with the dealer'])
            ->assertForbidden();
    }

    public function test_an_all_branch_stock_person_can_still_change_the_courier(): void
    {
        $order = $this->order('approved', $this->otherBranch);
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        $this->actingAs($store)->onPortal('manage')
            ->from('/orders/'.$order->id)
            ->post('/commands/orders/'.$order->id.'/courier', ['courier' => 'redx'])
            ->assertRedirect();

        $this->assertSame('redx', $order->fresh()->courier_choice);
    }

    private function order(string $status, CompanyBranch $branch): Order
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $order = (new Order)->forceFill([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $dealer->id,
            'status' => $status,
            'total' => '1000.00',
            'paid' => '1000.00',
            'courier_choice' => 'steadfast',
            'fulfillment_branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('b', 64),
        ]);
        $order->save();

        return $order;
    }
}
