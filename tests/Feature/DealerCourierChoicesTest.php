<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the order form offers a dealer: only couriers with a connected booking system, and the
 * order is refused if the dealer sends any other.
 */
class DealerCourierChoicesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01711000000']);
        $this->saveCourier('steadfast', ['api_key' => 'key', 'api_secret' => 'secret']);
        $this->saveCourier('redx', ['api_key' => 'key']);
    }

    public function test_a_dealer_can_pick_only_couriers_with_a_connected_booking_system(): void
    {
        // Switched on but missing its secret: not connected. Manual couriers have no booking system at all.
        $this->saveCourier('paperfly', ['api_key' => 'key']);
        $this->saveCourier('sundarban', []);

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/purchase')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.courier_choices.0.key', 'steadfast')
                ->where('data.courier_choices.1.key', 'redx')
                ->where('data.courier_choices.2.key', 'company')
                ->count('data.courier_choices', 3));

        foreach (['paperfly', 'sundarban'] as $refused) {
            $this->actingAs($this->dealer)->post('/commands/orders', [
                'items' => [['product_id' => $this->product()->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'courier_choice' => $refused,
            ])->assertSessionHasErrors('courier_choice');
        }
        $this->assertDatabaseCount('orders', 0);
    }

    /** @param  array<string, string>  $details */
    private function saveCourier(string $provider, array $details): void
    {
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($this->owner, 'couriers', $provider, [
            'enabled' => true,
            'base_url' => $provider === 'redx' ? 'https://redx.test' : 'https://courier.test/api/v1',
            ...$details,
        ], $settings->snapshot()['version'], 'Courier test');
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'CAB-'.Str::random(6),
            'name' => 'Courier Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
        ]);
    }
}
