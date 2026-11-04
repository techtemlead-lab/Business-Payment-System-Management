<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdversarialM1LocaleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_active_account_locale_switching_between_bn_and_en(): void
    {
        // 1. Test Bengali inactive partner
        $bnDealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'suspended',
            'locale' => 'bn',
        ]);

        $this->actingAs($bnDealer)->onPortal('dealer')->get('/')
            ->assertRedirect()
            ->assertSessionHasErrors([
                'email' => __('auth_ui.account_unavailable_contact', [], 'bn'),
            ]);

        $this->actingAs($bnDealer)->onPortal('dealer')->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonFragment(['message' => __('auth_ui.account_unavailable', [], 'bn')]);

        // 2. Test English inactive partner
        $enDealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'suspended',
            'locale' => 'en',
        ]);

        $this->actingAs($enDealer)->onPortal('dealer')->get('/')
            ->assertRedirect()
            ->assertSessionHasErrors([
                'email' => __('auth_ui.account_unavailable_contact', [], 'en'),
            ]);

        $this->assertSame('This account is unavailable. Contact the company.', __('auth_ui.account_unavailable_contact', [], 'en'));

        $this->actingAs($enDealer)->onPortal('dealer')->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonFragment(['message' => __('auth_ui.account_unavailable', [], 'en')]);

        $this->assertSame('This account is unavailable.', __('auth_ui.account_unavailable', [], 'en'));
    }

    public function test_order_service_decision_validation_switching_between_bn_and_en(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $product = Product::create([
            'sku' => 'ADV-LOC-'.Str::random(6),
            'name' => 'Adversarial Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
        ]);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // Bengali locale
        app()->setLocale('bn');
        try {
            app(OrderService::class)->decision($admin, $order, 'invalid_status', 'reason');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(__('portal.err_order_status_choice', [], 'bn'), $e->errors()['status'][0]);
            $this->assertSame('অনুমোদিত, আটক বা প্রত্যাখ্যাত বেছে নিন।', $e->errors()['status'][0]);
        }

        // English locale
        app()->setLocale('en');
        try {
            app(OrderService::class)->decision($admin, $order, 'invalid_status', 'reason');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(__('portal.err_order_status_choice', [], 'en'), $e->errors()['status'][0]);
            $this->assertSame('Choose approved, held or rejected.', $e->errors()['status'][0]);
        }

        // Now approve the order
        app(OrderService::class)->decision($admin, $order, 'approved', 'Authorized', true);

        // Bengali already decided message
        app()->setLocale('bn');
        try {
            app(OrderService::class)->decision($admin, $order->fresh(), 'rejected', 'No');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(__('portal.err_order_already_decided', [], 'bn'), $e->errors()['status'][0]);
            $this->assertSame('এই অর্ডার ইতিমধ্যে সিদ্ধান্ত নেওয়া হয়েছে।', $e->errors()['status'][0]);
        }

        // English already decided message
        app()->setLocale('en');
        try {
            app(OrderService::class)->decision($admin, $order->fresh(), 'rejected', 'No');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(__('portal.err_order_already_decided', [], 'en'), $e->errors()['status'][0]);
            $this->assertSame('This order has already been decided.', $e->errors()['status'][0]);
        }
    }

    public function test_order_service_dispatch_locale_switching(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $product = Product::create([
            'sku' => 'ADV-DIS-'.Str::random(6),
            'name' => 'Dispatch Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
        ]);
        $unapprovedOrder = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $line = $unapprovedOrder->lines()->first();

        // Bengali unapproved dispatch error
        app()->setLocale('bn');
        try {
            app(OrderService::class)->dispatch($admin, $unapprovedOrder, [
                'courier' => 'Test',
                'tracking' => 'TRK-1',
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['line_id' => $line->id, 'quantity' => 1]],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(__('portal.err_shipment_approve_first', [], 'bn'), $e->errors()['shipment'][0]);
            $this->assertSame('ডিসপ্যাচের আগে অর্ডার অনুমোদন করুন।', $e->errors()['shipment'][0]);
        }

        // English unapproved dispatch error
        app()->setLocale('en');
        try {
            app(OrderService::class)->dispatch($admin, $unapprovedOrder, [
                'courier' => 'Test',
                'tracking' => 'TRK-1',
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['line_id' => $line->id, 'quantity' => 1]],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(__('portal.err_shipment_approve_first', [], 'en'), $e->errors()['shipment'][0]);
            $this->assertSame('Approve the order before sending.', $e->errors()['shipment'][0]);
        }
    }

    public function test_api_dispatch_accept_language_header_switching(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $product = Product::create([
            'sku' => 'ADV-API-'.Str::random(6),
            'name' => 'Api Product',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
        ]);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        app(OrderService::class)->decision($admin, $order, 'approved', 'Authorized', true);
        $line = $order->lines()->first();

        // 1. Accept-Language: bn
        $this->actingAs($admin)
            ->withHeader('Accept-Language', 'bn')
            ->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
                'courier' => 'Test courier',
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['line_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tracking.0', __('portal.err_dispatch_tracking', [], 'bn'))
            ->assertJsonPath('errors.tracking.0', 'ট্র্যাকিং রেফারেন্স দিন, অথবা সক্রিয় কুরিয়ার API প্রদানকারী নির্বাচন করুন।');

        // 2. Accept-Language: en
        $this->actingAs($admin)
            ->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
                'courier' => 'Test courier',
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['line_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tracking.0', __('portal.err_dispatch_tracking', [], 'en'))
            ->assertJsonPath('errors.tracking.0', 'Enter a tracking reference, or select an enabled courier API provider.');
    }

    public function test_behavior_when_default_locale_is_changed(): void
    {
        $originalDefault = config('app.locale');

        try {
            // 1. Change default locale to 'bn'
            config(['app.locale' => 'bn']);
            app()->setLocale('bn');

            $dealerBn = User::factory()->create([
                'role' => 'dealer',
                'status' => 'suspended',
                'locale' => 'bn',
            ]);

            $this->actingAs($dealerBn)->onPortal('dealer')->get('/')
                ->assertRedirect()
                ->assertSessionHasErrors([
                    'email' => __('auth_ui.account_unavailable_contact', [], 'bn'),
                ]);

            // 2. Change default locale to 'en'
            config(['app.locale' => 'en']);
            app()->setLocale('en');

            $dealerEn = User::factory()->create([
                'role' => 'dealer',
                'status' => 'suspended',
                'locale' => 'en',
            ]);

            $this->actingAs($dealerEn)->onPortal('dealer')->get('/')
                ->assertRedirect()
                ->assertSessionHasErrors([
                    'email' => __('auth_ui.account_unavailable_contact', [], 'en'),
                ]);

            // 3. Verify that changing default locale to 'bn' does not cause OrderService or ActiveAccount tests to fail
            config(['app.locale' => 'bn']);
            app()->setLocale('bn');

            $this->actingAs($dealerBn)->onPortal('dealer')->getJson('/api/v1/me')
                ->assertForbidden()
                ->assertJsonFragment(['message' => __('auth_ui.account_unavailable', [], 'bn')]);

        } finally {
            config(['app.locale' => $originalDefault]);
            app()->setLocale($originalDefault);
        }
    }
}
