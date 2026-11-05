<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentVerificationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_the_payment_gateway_is_asked_before_the_money_lock_is_taken(): void
    {
        $this->enableRocket();
        $dealer = User::factory()->dealer()->create();
        $order = $this->order($dealer);
        $claimId = $this->claim($dealer, $order, 'TRX-SLOWGATEWAY-1');

        $baseline = DB::transactionLevel();
        $levels = [];
        Http::fake(function (Request $request) use (&$levels) {
            $levels[] = DB::transactionLevel();

            return Http::response(['status' => 'COMPLETED', 'amount' => '200.00']);
        });

        app(OrderService::class)->verify(User::factory()->staff('finance')->create(), $claimId, true, 'Matched on the gateway.');

        $this->assertNotEmpty($levels, 'The gateway was not asked.');
        $this->assertSame([$baseline], array_values(array_unique($levels)), 'The gateway was called inside a database transaction.');
        $this->assertSame('verified', DB::table('payment_claims')->where('id', $claimId)->value('status'));
        $this->assertSame('200.00', $order->fresh()->paid);
    }

    public function test_a_rejected_transaction_id_can_be_claimed_again_but_an_open_one_cannot(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->order($dealer);
        $finance = User::factory()->staff('finance')->create();

        $first = $this->claim($dealer, $order, 'TRX-BLURRY-1');
        $this->claimRequest($dealer, $order, 'TRX-BLURRY-1')->assertUnprocessable()->assertJsonValidationErrors(['reference']);

        app(OrderService::class)->verify($finance, $first, false, 'Screenshot is unreadable, please send it again.');
        $second = $this->claim($dealer, $order, 'TRX-BLURRY-1');

        $this->assertNotSame($first, $second);
        app(OrderService::class)->verify($finance, $second, true, 'Clear screenshot received.');
        $this->assertSame('200.00', $order->fresh()->paid);
        $this->claimRequest($dealer, $order, 'TRX-BLURRY-1')->assertUnprocessable()->assertJsonValidationErrors(['reference']);
    }

    private function claim(User $dealer, Order $order, string $reference): int
    {
        $this->claimRequest($dealer, $order, $reference)->assertCreated();

        return (int) DB::table('payment_claims')->where('reference', $reference)->where('status', 'pending')->value('id');
    }

    private function claimRequest(User $dealer, Order $order, string $reference): TestResponse
    {
        return $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'Rocket', 'reference' => $reference, 'amount' => '200.00',
            'proof' => UploadedFile::fake()->image('rocket.png'),
        ]);
    }

    private function order(User $buyer): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => 'submitted',
            'total' => '500.00',
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    private function enableRocket(): void
    {
        $owner = User::factory()->staff()->create();
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($owner, 'payments', 'rocket', [
            'enabled' => true, 'base_url' => 'https://rocket.test', 'api_key' => 'key', 'api_secret' => 'secret', 'mode' => 'sandbox',
        ], $settings->snapshot()['version'], 'Gateway lock test');
    }
}
