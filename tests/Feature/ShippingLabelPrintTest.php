<?php

namespace Tests\Feature;

use App\Domain\Orders\ShippingLabels;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShippingLabelPrintTest extends TestCase
{
    use RefreshDatabase;

    private const ReceiverPhone = '8801712345678';

    private User $dealer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->dealer = User::factory()->dealer()->create([
            'status' => 'active',
            'name' => 'Rahima Parcel Receiver',
            'phone' => self::ReceiverPhone,
            'division' => 'Rangpur',
            'district' => 'Rangpur',
            'profile' => ['address' => 'Station Road, Holding 12', 'union' => 'Char Union', 'upazila' => 'Pirganj', 'place_code' => 'R-100'],
        ]);
        $this->order = $this->order('approved');
    }

    private function order(string $status): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $this->dealer->id,
            'status' => $status,
            'total' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        $product = Product::factory()->create();
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 3, 'mrp' => '50.00', 'unit_price' => '50.00', 'total' => '150.00']);

        return $order;
    }

    private function url(?Order $order = null): string
    {
        return '/orders/'.($order ?? $this->order)->id.'/shipping-label';
    }

    public function test_dispatch_staff_see_the_full_name_address_and_phone(): void
    {
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        $this->actingAs($store)->onPortal('manage')->get($this->url())->assertOk()
            ->assertSee('Rahima Parcel Receiver')
            ->assertSee(self::ReceiverPhone)
            ->assertSee('Station Road, Holding 12')
            ->assertSee('Char Union')
            ->assertSee('Pirganj')
            ->assertSee('Rangpur')
            ->assertSee($this->order->number)
            ->assertDontSee('017****5678');
    }

    public function test_every_print_is_written_on_the_activity_log_without_the_phone(): void
    {
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        $this->actingAs($store)->onPortal('manage')->get($this->url())->assertOk();
        $this->actingAs($store)->onPortal('manage')->get($this->url())->assertOk();

        $events = DB::table('audit_events')->where('action', 'shipment.label_printed')->get();
        $this->assertCount(2, $events);
        $this->assertSame($store->id, $events[0]->actor_id);
        $this->assertStringNotContainsString(self::ReceiverPhone, (string) $events[0]->metadata);
        $this->assertStringContainsString($this->order->number, (string) $events[0]->metadata);
    }

    public function test_the_owner_can_print_too(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')->get($this->url())->assertOk()->assertSee(self::ReceiverPhone);
    }

    public function test_staff_who_do_not_handle_dispatch_cannot_print(): void
    {
        foreach (['finance', 'accounts'] as $role) {
            $staff = User::factory()->staff($role)->create(['status' => 'active']);

            $this->actingAs($staff)->onPortal('manage')->get($this->url())->assertForbidden();
        }
        $this->assertDatabaseMissing('audit_events', ['action' => 'shipment.label_printed']);
    }

    public function test_partners_cannot_reach_the_label(): void
    {
        foreach ([$this->dealer, User::factory()->dealer()->create(['status' => 'active'])] as $partner) {
            $response = $this->actingAs($partner)->onPortal('dealer')->get($this->url());

            $this->assertContains($response->getStatusCode(), [403, 404]);
            $response->assertDontSee(self::ReceiverPhone);
        }
        $this->assertDatabaseMissing('audit_events', ['action' => 'shipment.label_printed']);
    }

    public function test_staff_cannot_print_a_label_for_their_own_order(): void
    {
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);
        $this->order->forceFill(['user_id' => $store->id])->save();

        $this->actingAs($store)->onPortal('manage')->get($this->url())->assertForbidden();
    }

    public function test_an_order_not_yet_approved_or_already_closed_has_no_label(): void
    {
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        foreach (['submitted', 'rejected', 'delivered'] as $status) {
            $this->actingAs($store)->onPortal('manage')->get($this->url($this->order($status)))->assertStatus(422);
        }
        $this->assertDatabaseMissing('audit_events', ['action' => 'shipment.label_printed']);
    }

    public function test_the_label_shows_the_whole_address(): void
    {
        $this->assertSame(
            'Station Road, Holding 12, Char Union, Pirganj, Rangpur, Rangpur, '.__('courier.place_code_in_address', ['code' => 'R-100']),
            ShippingLabels::deliveryAddress($this->dealer),
        );
    }

    public function test_the_packing_slips_stay_masked(): void
    {
        $slip = (string) view('bulk.order-slips', ['orders' => Order::query()->with(['user', 'lines', 'fulfillmentBranch'])->whereKey($this->order->id)->get()])->render();

        $this->assertStringNotContainsString(self::ReceiverPhone, $slip);
        $this->assertStringContainsString('017****5678', $slip);
    }

    public function test_the_print_script_is_allowed_by_the_page_security_rules(): void
    {
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        $response = $this->actingAs($store)->onPortal('manage')->get($this->url())->assertOk();

        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $response->assertSee('nonce="'.$matches[1].'"', false);
    }

    public function test_the_label_is_not_cached_by_the_browser(): void
    {
        $store = User::factory()->staff('inventory')->create(['status' => 'active']);

        $response = $this->actingAs($store)->onPortal('manage')->get($this->url())->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
