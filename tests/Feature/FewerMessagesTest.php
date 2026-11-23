<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner's word (2026-11-23): one event, one message. SMS, email and push each cost money, so what
 * used to go out as two or more texts for the same moment now goes out as one.
 */
class FewerMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_an_order_placed_with_a_mobile_payment_sends_the_partner_one_notice_and_one_sms(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01720000066', 'locale' => 'bn']);
        $product = Product::factory()->create(['active' => true, 'mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 50]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'bKash',
            'reference' => 'TRX-ONE-1',
            'amount' => '100.00',
            'proof' => UploadedFile::fake()->image('bkash.png'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('account_notices')->where('user_id', $dealer->id)->count());
        $this->assertSame(['order.submitted'], collect(config('madina.sms.sent'))->where('user_id', $dealer->id)->pluck('event')->values()->all());
    }

    public function test_the_office_gets_one_notice_for_an_order_with_a_mobile_payment(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $owner = User::factory()->staff('super_admin')->create();
        $product = Product::factory()->create(['active' => true, 'mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 50]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'bKash',
            'reference' => 'TRX-ONE-2',
            'amount' => '100.00',
            'proof' => UploadedFile::fake()->image('bkash.png'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('account_notices')->where('user_id', $owner->id)->count());
    }
}
