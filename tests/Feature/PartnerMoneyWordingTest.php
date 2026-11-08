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
use Tests\TestCase;

/**
 * What a partner reads about their own money: on a Bangla account the words and the
 * figures are in Bangla, money reads as money, and field names are never computer names.
 */
class PartnerMoneyWordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['madina.full_payment_when_ordering' => true, 'madina.financial_activation' => true, 'madina.preview_mode' => false]);
    }

    public function test_payment_notice_writes_taka_and_the_method_in_bangla(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'bn']);
        $product = Product::factory()->create(['active' => true, 'mrp' => '20000.00', 'dealer_price' => '12500.00', 'sub_dealer_price' => '13000.00', 'stock' => 50]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'bKash',
            'reference' => 'TRX-WORDING-1',
            'amount' => '12500.00',
            'proof' => UploadedFile::fake()->image('bkash.png'),
        ])->assertRedirect();

        $order = Order::query()->where('user_id', $dealer->id)->firstOrFail();
        $body = (string) DB::table('account_notices')->where('user_id', $dealer->id)
            ->where('title', __('portal.notice_payment_claimed_title', [], 'bn'))
            ->value('body');

        $this->assertSame($order->number.': বিকাশ দিয়ে ৳১২,৫০০.০০ যাচাইয়ের অপেক্ষায়।', $body);
    }

    public function test_full_amount_refusal_is_written_in_bangla_digits(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'bn']);
        $product = Product::factory()->create(['active' => true, 'mrp' => '20000.00', 'dealer_price' => '12500.00', 'sub_dealer_price' => '13000.00', 'stock' => 50]);

        $response = $this->actingAs($dealer)->onPortal('dealer')->withSession(['locale' => 'bn'])->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'bKash',
            'reference' => 'TRX-WORDING-2',
            'amount' => '100.00',
            'proof' => UploadedFile::fake()->image('bkash.png'),
        ]);

        $response->assertSessionHasErrors('amount');
        $message = (string) session('errors')->first('amount');
        $this->assertStringContainsString('১২,৫০০.০০', $message);
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', $message);
    }

    public function test_a_short_payout_number_is_refused_with_a_bangla_field_name(): void
    {
        $this->enableFinancialPosting();
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'bn']);

        $response = $this->actingAs($dealer)->onPortal('dealer')->withSession(['locale' => 'bn'])->post('/commands/withdrawals', [
            'amount' => '250',
            'channel' => 'bKash',
            'destination' => '123',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors('destination');
        $message = (string) session('errors')->first('destination');
        $this->assertStringContainsString('অ্যাকাউন্ট বা ওয়ালেট নম্বর', $message);
        $this->assertStringNotContainsString('destination', $message);
    }

    public function test_the_minimum_withdrawal_refusal_is_written_in_bangla_digits(): void
    {
        $this->enableFinancialPosting();
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'bn']);

        $response = $this->actingAs($dealer)->onPortal('dealer')->withSession(['locale' => 'bn'])->post('/commands/withdrawals', [
            'amount' => '50',
            'channel' => 'bKash',
            'destination' => '01712345678',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors('amount');
        $message = (string) session('errors')->first('amount');
        $this->assertStringContainsString('৳', $message);
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', $message);
    }
}
