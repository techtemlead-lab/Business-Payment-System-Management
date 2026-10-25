<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderServiceLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_decision_validation_uses_bangla_when_locale_is_bn(): void
    {
        app()->setLocale('bn');
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'locale' => 'bn']);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $product = Product::create([
            'sku' => 'LOC-'.Str::random(6),
            'name' => 'Locale Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
        ]);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            app(OrderService::class)->decision($admin, $order, 'mystery', 'n/a');
            $this->fail('Expected ValidationException for invalid status');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'অনুমোদিত, হোল্ড বা প্রত্যাখ্যাত বেছে নিন।',
                $exception->errors()['status'][0] ?? null,
            );
        }

        app(OrderService::class)->decision($admin, $order, 'held', 'Need payment proof');
        app(OrderService::class)->decision($admin, $order->fresh(), 'approved', 'Credit authorized', true);

        try {
            app(OrderService::class)->decision($admin, $order->fresh(), 'rejected', 'No');
            $this->fail('Expected ValidationException for decided order');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'এই অর্ডার ইতিমধ্যে সিদ্ধান্ত নেওয়া হয়েছে।',
                $exception->errors()['status'][0] ?? null,
            );
        }

        $notice = DB::table('account_notices')->where('user_id', $dealer->id)->orderByDesc('id')->first();
        $this->assertNotNull($notice);
        $this->assertStringContainsString('অনুমোদিত', (string) $notice->title);
        $this->assertStringContainsString($order->number, (string) $notice->body);
    }

    public function test_dispatch_before_approval_returns_localized_error(): void
    {
        app()->setLocale('bn');
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $product = Product::create([
            'sku' => 'SHP-'.Str::random(6),
            'name' => 'Ship Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
        ]);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $line = $order->lines()->first();

        try {
            app(OrderService::class)->dispatch($admin, $order, [
                'courier' => 'Test',
                'tracking' => 'TRACK-BN-1',
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['line_id' => $line->id, 'quantity' => 1]],
            ]);
            $this->fail('Expected ValidationException for unapproved dispatch');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'ডিসপ্যাচের আগে অর্ডার অনুমোদন করুন।',
                $exception->errors()['shipment'][0] ?? null,
            );
        }
    }
}
