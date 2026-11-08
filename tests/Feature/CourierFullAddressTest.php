<?php

namespace Tests\Feature;

use App\Domain\Integrations\CourierAreaMapping;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ShippingLabels;
use App\Jobs\BookShipmentCourier;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The address each courier company is sent when a parcel is booked. It must be the same address
 * the shipping label shows, with the union and upazila from the partner's profile. Couriers are
 * never called for real: every request goes to Http::fake().
 */
class CourierFullAddressTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private PlaceCode $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->place = PlaceCode::factory()->union(PlaceCode::factory()->district(PlaceCode::factory()->division()->create())->create())->create();
        foreach (['pathao' => ['city_id' => '1', 'zone_id' => '52', 'area_id' => '908'], 'redx' => ['area_id' => '14', 'area_name' => 'Pirganj']] as $provider => $ids) {
            app(CourierAreaMapping::class)->save($this->owner, ['provider' => $provider, 'place_code_id' => $this->place->id, ...$ids]);
        }
    }

    /**
     * @return array<string, array{provider: string, addressField: string, answer: array<string, mixed>}>
     */
    public static function courierDrivers(): array
    {
        return [
            'pathao' => ['provider' => 'pathao', 'addressField' => 'recipient_address', 'answer' => ['data' => ['consignment_id' => 'DP-1']]],
            'steadfast' => ['provider' => 'steadfast', 'addressField' => 'recipient_address', 'answer' => ['consignment' => ['consignment_id' => 'CN-1', 'tracking_code' => 'SF-1']]],
            'redx' => ['provider' => 'redx', 'addressField' => 'customer_address', 'answer' => ['tracking_id' => 'RX-1']],
            'paperfly' => ['provider' => 'paperfly', 'addressField' => 'customerAddress', 'answer' => ['response_code' => 200, 'success' => ['tracking_number' => 'PF-1']]],
            'ecourier' => ['provider' => 'ecourier', 'addressField' => 'recipient_address', 'answer' => ['response_code' => 200, 'ID' => 'ECR-1']],
        ];
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    #[DataProvider('courierDrivers')]
    public function test_every_courier_is_sent_the_full_address(string $provider, string $addressField, array $answer): void
    {
        $this->enableCourier($provider);
        Http::fake(['courier.test/*' => Http::response($answer)]);
        $dealer = $this->dealer();

        $this->assertSame('booked', $this->book($provider, $dealer)->courier_booking_status);

        $expected = 'Station Road, Holding 12, Char Union, Pirganj, Rangpur Sadar, Rangpur, '.__('courier.place_code_in_address', ['code' => $this->place->code]);
        $this->assertSame($expected, ShippingLabels::deliveryAddress($dealer), 'The label and the courier must read the same address.');
        Http::assertSent(fn (Request $request): bool => $request[$addressField] === $expected);
    }

    public function test_ecourier_is_told_the_city_thana_and_area_separately(): void
    {
        $this->enableCourier('ecourier');
        Http::fake(['courier.test/*' => Http::response(['response_code' => 200, 'ID' => 'ECR-1'])]);

        $this->book('ecourier', $this->dealer());

        Http::assertSent(fn (Request $request): bool => $request['recipient_city'] === 'Rangpur Sadar'
            && $request['recipient_thana'] === 'Pirganj'
            && $request['recipient_area'] === 'Char Union');
    }

    public function test_ecourier_uses_the_upazila_as_the_area_when_the_partner_has_no_union(): void
    {
        $this->enableCourier('ecourier');
        Http::fake(['courier.test/*' => Http::response(['response_code' => 200, 'ID' => 'ECR-1'])]);

        $this->book('ecourier', $this->dealer(['union' => '']));

        Http::assertSent(fn (Request $request): bool => $request['recipient_thana'] === 'Pirganj' && $request['recipient_area'] === 'Pirganj');
    }

    public function test_a_place_the_partner_never_gave_leaves_no_gap_in_the_address(): void
    {
        $this->enableCourier('steadfast');
        Http::fake(['courier.test/*' => Http::response(['consignment' => ['consignment_id' => 'CN-1', 'tracking_code' => 'SF-1']])]);

        $this->book('steadfast', $this->dealer(['union' => null, 'upazila' => '']));

        Http::assertSent(fn (Request $request): bool => $request['recipient_address'] === 'Station Road, Holding 12, Rangpur Sadar, Rangpur, '.__('courier.place_code_in_address', ['code' => $this->place->code]));
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function dealer(array $profile = []): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01711000000', 'division' => 'Rangpur', 'district' => 'Rangpur Sadar']);
        $dealer->forceFill(['profile' => [
            'address' => 'Station Road, Holding 12',
            'union' => 'Char Union',
            'upazila' => 'Pirganj',
            'place_code' => $this->place->code,
            'place_code_id' => $this->place->id,
            ...$profile,
        ]])->save();

        return $dealer;
    }

    private function enableCourier(string $provider): void
    {
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($this->owner, 'couriers', $provider, [
            'enabled' => true,
            'base_url' => 'https://courier.test',
            'api_key' => 'key',
            'api_secret' => 'secret',
            ...match ($provider) {
                'ecourier' => ['user_id' => 'U1', 'package_code' => '#7777'],
                'paperfly' => ['username' => 'merchant', 'password' => 'pw'],
                default => [],
            },
        ], $settings->snapshot()['version'], 'Courier test');
    }

    private function book(string $provider, User $dealer): object
    {
        $product = Product::create(['sku' => 'CFA-'.Str::random(6), 'name' => 'Address Product', 'category' => 'Test', 'mrp' => '200.00', 'dealer_price' => '140.00', 'stock' => 20]);
        $order = app(OrderService::class)->create($dealer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'courier_choice' => $provider]);
        $order->update(['paid' => $order->total, 'status' => 'pending_approval']);
        $shipmentId = DB::table('shipments')->insertGetId(['order_id' => $order->id, 'actor_id' => $this->owner->id, 'courier' => ucfirst($provider), 'courier_provider' => $provider,
            'courier_booking_status' => 'pending', 'tracking' => 'PENDING-'.Str::random(6), 'status' => 'dispatched', 'lines' => '[]', 'created_at' => now(), 'updated_at' => now()]);

        BookShipmentCourier::dispatchSync($shipmentId);

        return DB::table('shipments')->where('id', $shipmentId)->first();
    }
}
