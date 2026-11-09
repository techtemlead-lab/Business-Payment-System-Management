<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_a_payment_without_a_screenshot_is_refused(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->order($dealer);

        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'bKash', 'reference' => 'TRX-NOPROOF-1', 'amount' => '500.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['proof']);

        $this->assertDatabaseCount('payment_claims', 0);
    }

    public function test_only_images_and_pdfs_up_to_five_megabytes_are_accepted(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->order($dealer);

        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'bKash', 'reference' => 'TRX-BADFILE-1', 'amount' => '500.00',
            'proof' => UploadedFile::fake()->create('notes.exe', 10, 'application/x-msdownload'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['proof']);

        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'bKash', 'reference' => 'TRX-BIGFILE-1', 'amount' => '500.00',
            'proof' => UploadedFile::fake()->image('huge.jpg')->size(6000),
        ])->assertUnprocessable()->assertJsonValidationErrors(['proof']);
    }

    public function test_the_screenshot_is_stored_privately_and_finance_can_open_it_but_other_dealers_cannot(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->order($dealer);

        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'Nagad', 'reference' => 'TRX-PROOF-1', 'amount' => '500.00',
            'proof' => UploadedFile::fake()->image('nagad.png'),
        ])->assertCreated();

        $claim = DB::table('payment_claims')->where('reference', 'TRX-PROOF-1')->first();
        $this->assertNotNull($claim->proof_path);
        $this->assertSame(64, strlen((string) $claim->proof_sha256));
        Storage::disk('local')->assertExists($claim->proof_path);

        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('sadmin')
            ->get('/commands/operations/receipts/payment/'.$claim->id)->assertOk();
        $this->actingAs(User::factory()->dealer()->create())->onPortal('dealer')
            ->get('/commands/operations/receipts/payment/'.$claim->id)->assertNotFound();
    }

    public function test_the_payments_list_shows_that_a_screenshot_exists_without_exposing_its_path(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->order($dealer);
        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'Bank', 'reference' => 'TRX-LIST-1', 'amount' => '500.00',
            'proof' => UploadedFile::fake()->image('bank.jpg'),
        ])->assertCreated();

        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.payments_table.rows.data.0.has_proof', true)
                ->missing('data.payments_table.rows.data.0.proof_path'));
    }

    public function test_an_order_placed_with_a_payment_reference_needs_the_screenshot_too(): void
    {
        $dealer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['dealer_price' => '500.00', 'mrp' => '800.00', 'stock' => 10]);

        $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'channel' => 'bKash', 'reference' => 'TRX-ATORDER-1', 'amount' => '500.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['proof']);
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
}
