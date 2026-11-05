<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Models\BranchProductStock;
use App\Models\CommissionAllocation;
use App\Models\CompanyBranch;
use App\Models\OrderLine;
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
                __('portal.err_order_status_choice', [], 'bn'),
                $exception->errors()['status'][0] ?? null,
            );
        }

        app(OrderService::class)->decision($admin, $order, 'held', 'Need payment proof');
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($admin, $order->fresh(), 'approved', 'Paid in full');

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
                'মাল পাঠানোর আগে অর্ডার অনুমোদন করুন।',
                $exception->errors()['shipment'][0] ?? null,
            );
        }
    }

    public function test_dispatch_request_requires_tracking_or_provider_in_bangla(): void
    {
        app()->setLocale('bn');
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'locale' => 'bn']);
        $product = Product::create([
            'sku' => 'TRK-'.Str::random(6),
            'name' => 'Track Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
        ]);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($admin, $order->fresh(), 'approved', 'Paid in full');
        $line = $order->lines()->first();

        $this->actingAs($admin)
            ->withHeader('Accept-Language', 'bn')
            ->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
                'courier' => 'Test courier',
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['line_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tracking.0', __('portal.err_dispatch_tracking', [], 'bn'));
    }

    public function test_order_and_product_relationships_are_properly_bound(): void
    {
        $branch = CompanyBranch::factory()->create();
        $product = Product::create([
            'sku' => 'REL-'.Str::random(6),
            'name' => 'Relation Product',
            'category' => 'Test',
            'mrp' => '500.00',
            'dealer_price' => '350.00',
            'stock' => 50,
        ]);
        $stock = BranchProductStock::create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 15,
        ]);

        $this->assertTrue($product->branchStocks->contains($stock));
        $this->assertEquals($product->id, $stock->product->id);

        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        /** @var OrderLine $line */
        $line = $order->lines()->first();
        $this->assertNotNull($line);
        $this->assertEquals($order->id, $line->order->id);
        $this->assertEquals($product->id, $line->product->id);

        $allocation = CommissionAllocation::create([
            'order_id' => $order->id,
            'user_id' => $dealer->id,
            'category' => 'dealer_margin',
            'rate_percentage' => 10.00,
            'cash_basis' => 700.00,
            'amount' => 70.00,
            'status' => 'available',
        ]);

        $this->assertTrue($order->allocations->contains($allocation));
        $this->assertCount(0, $order->paymentClaims);
        $this->assertCount(0, $order->receipts);
        $this->assertCount(0, $order->shipments);
    }
}
