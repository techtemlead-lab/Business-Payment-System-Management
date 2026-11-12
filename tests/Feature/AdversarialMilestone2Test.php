<?php

namespace Tests\Feature;

use App\Domain\Finance\DistributionCalculator;
use App\Domain\Pricing\ProductPricing;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdversarialMilestone2Test extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * =========================================================================
     * 1. Wholesale Pricing & ProductPricing Contract Edge Cases
     * =========================================================================
     */

    /**
     * 1a: Attempt to pass zero, negative, or above-MRP prices for dealer.
     * Verifies that zero, negative, or above-MRP prices are strictly rejected.
     */
    public function test_pricing_contract_rejects_zero_negative_and_above_mrp_dealer_price(): void
    {
        $pricing = app(ProductPricing::class);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        // Case 1: Zero dealer price (triggers line 32: $price <= 0 check)
        $productZero = Product::factory()->create(['mrp' => '500.00', 'dealer_price' => '0.00']);
        try {
            $pricing->unitPrice($productZero, $dealer);
            $this->fail('Expected ValidationException for zero dealer price.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        // Case 2: Negative dealer price (rejected by Money::cents or price <= 0)
        $productNegative = Product::factory()->create(['mrp' => '500.00', 'dealer_price' => '-50.00']);
        $negativeRejected = false;
        try {
            $pricing->unitPrice($productNegative, $dealer);
            $this->fail('Expected rejection for negative dealer price.');
        } catch (ValidationException|InvalidArgumentException $e) {
            $negativeRejected = true;
        }
        $this->assertTrue($negativeRejected, 'Negative dealer price must be strictly rejected.');

        // Case 3: Dealer price strictly exceeding MRP (triggers line 32: $price > $mrp)
        $productAbove = Product::factory()->create(['mrp' => '500.00', 'dealer_price' => '600.00']);
        try {
            $pricing->unitPrice($productAbove, $dealer);
            $this->fail('Expected ValidationException for dealer price exceeding MRP.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        // Case 4: Dealer price null for dealer role (triggers line 28: missing dealer price check)
        $productNull = Product::factory()->create(['mrp' => '500.00', 'dealer_price' => null]);
        try {
            $pricing->unitPrice($productNull, $dealer);
            $this->fail('Expected ValidationException for null dealer price.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
    }

    /**
     * 1b: Attempt to pass zero, negative, or above-MRP prices for sub-dealer.
     */
    public function test_pricing_contract_rejects_zero_negative_and_above_mrp_sub_dealer_price(): void
    {
        $pricing = app(ProductPricing::class);
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        // Case 1: Zero sub_dealer_price
        $productZero = Product::factory()->create(['mrp' => '400.00', 'dealer_price' => '200.00', 'sub_dealer_price' => '0.00']);
        try {
            $pricing->unitPrice($productZero, $subDealer);
            $this->fail('Expected ValidationException for zero sub-dealer price.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        // Case 2: Negative sub_dealer_price (caught and converted to ValidationException)
        $productNegative = Product::factory()->create(['mrp' => '400.00', 'dealer_price' => '200.00', 'sub_dealer_price' => '-20.00']);
        try {
            $pricing->unitPrice($productNegative, $subDealer);
            $this->fail('Expected ValidationException for negative sub-dealer price.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        // Case 3: Sub-dealer price strictly exceeding MRP
        $productAbove = Product::factory()->create(['mrp' => '400.00', 'dealer_price' => '200.00', 'sub_dealer_price' => '450.00']);
        try {
            $pricing->unitPrice($productAbove, $subDealer);
            $this->fail('Expected ValidationException for sub-dealer price exceeding MRP.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
    }

    /**
     * 1c: Non-wholesaler roles (guest, customer, staff roles) are strictly forbidden from unitPrice calculation.
     */
    public function test_pricing_contract_strictly_aborts_for_non_wholesalers(): void
    {
        $pricing = app(ProductPricing::class);
        $product = Product::factory()->create(['mrp' => '300.00', 'dealer_price' => '200.00']);

        $nonWholesalerRoles = ['customer', 'super_admin', 'finance', 'inventory', 'editor', 'sales', 'auditor'];

        foreach ($nonWholesalerRoles as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            try {
                $pricing->unitPrice($product, $user);
                $this->fail("Expected 403 HttpException for non-wholesaler role: {$role}");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), "Role {$role} must return 403.");
            }
        }
    }

    /**
     * 1d: Discount bounds invariant: bounded strictly within 20% to 50% discount limits in DistributionCalculator.
     */
    public function test_discount_bounds_strictly_enforced_between_20_and_50_percent(): void
    {
        $calculator = app(DistributionCalculator::class);

        // Sub-20% discount bounds (e.g. 19.99%, 15%, 0%) must be rejected
        $invalidLowDiscounts = ['19.99', '15.00', '5.00', '0.00'];
        foreach ($invalidLowDiscounts as $discount) {
            try {
                $calculator->calculate('1000.00', 'dealer', $discount);
                $this->fail("Expected InvalidArgumentException for low discount: {$discount}%");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Product discounts must be between 20% and 50%', $e->getMessage());
            }
        }

        // Negative discount percentages are malformed percentages
        try {
            $calculator->calculate('1000.00', 'dealer', '-10.00');
            $this->fail('Expected InvalidArgumentException for negative discount.');
        } catch (InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Above-50% discount bounds (e.g. 50.01%, 55%, 60%, 80%) must be rejected
        $invalidHighDiscounts = ['50.01', '55.00', '60.00', '80.00'];
        foreach ($invalidHighDiscounts as $discount) {
            try {
                $calculator->calculate('1000.00', 'dealer', $discount);
                $this->fail("Expected InvalidArgumentException for high discount: {$discount}%");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Product discounts must be between 20% and 50%', $e->getMessage());
            }
        }

        // Boundary valid values: exactly 20.00% and exactly 50.00% must succeed
        $calc20 = $calculator->calculate('1000.00', 'dealer', '20.00');
        $this->assertSame('20.00', $calc20['discount_percent']);
        $this->assertSame('800.00', $calc20['purchase_value']);

        $calc50 = $calculator->calculate('1000.00', 'dealer', '50.00');
        $this->assertSame('50.00', $calc50['discount_percent']);
        $this->assertSame('500.00', $calc50['purchase_value']);
    }

    /**
     * 1e: Staff product catalog commands reject dealer discounts outside 20-50% range.
     */
    public function test_product_registry_enforces_discount_limits_on_commands(): void
    {
        $staff = User::factory()->staff('super_admin')->create(['status' => 'active']);
        $product = Product::factory()->create(['mrp' => '1000.00', 'dealer_price' => '700.00']);
        $product->refresh();

        // Attempting 10% discount (dealer_price = 900.00, below 20% minimum) -> fails validation
        $responseLow = $this->actingAs($staff)->onPortal('sadmin')->patch('/commands/products/'.$product->id, [
            'name' => $product->name,
            'mrp' => '1000.00',
            'dealer_price' => '900.00', // 10% discount < 20% min
            'version' => (string) ($product->registry_version ?? 0),
            'reason' => 'Testing below minimum discount',
        ]);
        $responseLow->assertSessionHasErrors(['dealer_price']);

        // Attempting 80% discount (dealer_price = 200.00, exceeds owner 70% max) -> fails validation
        $responseHigh = $this->actingAs($staff)->onPortal('sadmin')->patch('/commands/products/'.$product->id, [
            'name' => $product->name,
            'mrp' => '1000.00',
            'dealer_price' => '200.00', // 80% discount > 70% max
            'version' => (string) ($product->registry_version ?? 0),
            'reason' => 'Testing above maximum discount',
        ]);
        $responseHigh->assertSessionHasErrors(['dealer_price']);
    }

    /**
     * =========================================================================
     * 2. Financial Preview Non-Posting Invariant
     * =========================================================================
     */

    /**
     * 2a: Distribution preview returns posts_earnings = false and causes ZERO wallet mutations.
     */
    public function test_distribution_preview_never_posts_earnings_and_leaves_wallets_untouched(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);
        $walletsBefore = DB::table('wallets')->get();
        $journalBefore = DB::table('journal_entries')->count();
        $allocationsBefore = DB::table('commission_allocations')->count();
        $policyDraftsBefore = DB::table('policy_drafts')->count();

        $payload = [
            'account_type' => 'sub_dealer',
            'mrp' => '5000.00',
            'discount_percent' => '40.00',
            'company_share_percent' => '30.00',
        ];

        $response = $this->actingAs($finance)->postJson('/api/v1/finance/distribution-preview', $payload);

        $response->assertOk();
        $response->assertJsonPath('data.posts_earnings', false);
        $this->assertFalse($response->json('data.posts_earnings'));

        // Assert zero database mutations
        $this->assertEquals($walletsBefore, DB::table('wallets')->get());
        $this->assertSame($journalBefore, DB::table('journal_entries')->count());
        $this->assertSame($allocationsBefore, DB::table('commission_allocations')->count());
        $this->assertSame($policyDraftsBefore, DB::table('policy_drafts')->count());
    }

    /**
     * 2b: Salary preview returns posts_earnings = false and causes ZERO database mutations.
     */
    public function test_salary_preview_never_posts_earnings_and_leaves_wallets_untouched(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);
        $walletsBefore = DB::table('wallets')->get();
        $journalBefore = DB::table('journal_entries')->count();

        $response = $this->actingAs($finance)->postJson('/api/v1/finance/salary-preview', [
            'monthly_purchases' => '250000.00',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.posts_earnings', false);
        $response->assertJsonPath('data.salary', '7500.00'); // 3% of 250000
        $response->assertJsonPath('data.tour', '7500.00');
        $this->assertFalse($response->json('data.posts_earnings'));

        // Assert zero wallet / ledger records created
        $this->assertEquals($walletsBefore, DB::table('wallets')->get());
        $this->assertSame($journalBefore, DB::table('journal_entries')->count());
    }

    /**
     * 2c: Defaults endpoint returns posts_earnings = false.
     */
    public function test_financial_defaults_specifies_posts_earnings_false(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        $response = $this->actingAs($finance)->getJson('/api/v1/finance/defaults');

        $response->assertOk();
        $response->assertJsonPath('data.posts_earnings', false);
        $response->assertJsonPath('data.discount_range.minimum', '20.00');
        $response->assertJsonPath('data.discount_range.maximum', '50.00');
    }

    /**
     * 2d: Even when financial posting is explicitly enabled via enableFinancialPosting(),
     * previews still return posts_earnings = false and do not post to wallets.
     */
    public function test_previews_remain_non_posting_even_when_financial_activation_is_armed(): void
    {
        $this->enableFinancialPosting();

        $finance = User::factory()->staff('finance')->create(['status' => 'active']);
        $walletsBefore = DB::table('wallets')->get();

        $resDist = $this->actingAs($finance)->postJson('/api/v1/finance/distribution-preview', [
            'account_type' => 'dealer',
            'mrp' => '10000.00',
            'discount_percent' => '30.00',
        ]);
        $resDist->assertOk()->assertJsonPath('data.posts_earnings', false);

        $resSalary = $this->postJson('/api/v1/finance/salary-preview', [
            'monthly_purchases' => '500000.00',
        ]);
        $resSalary->assertOk()->assertJsonPath('data.posts_earnings', false);

        // Wallets must remain strictly untouched
        $this->assertEquals($walletsBefore, DB::table('wallets')->get());
    }

    /**
     * =========================================================================
     * 3. API Error Handling: Structured 422 JSON Validation Responses
     * =========================================================================
     */

    /**
     * 3a: Probe POST /api/v1/orders with invalid payloads for structured 422 JSON.
     */
    public function test_orders_endpoint_returns_structured_422_on_invalid_payloads(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        // Probe 1: Empty body
        $resEmpty = $this->actingAs($dealer)->postJson('/api/v1/orders', []);
        $resEmpty->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'errors' => ['items', 'idempotency_key']]);

        // Probe 2: Zero / negative quantity
        $product = Product::factory()->create();
        $resInvalidQty = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 0]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $resInvalidQty->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        // Probe 3: Non-existent product ID
        $resNonExistent = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => 99999999, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $resNonExistent->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');

        // Probe 4: Extra unallowed keys in line items (schema whitelist enforcement)
        $resExtraKeys = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'injected_price' => '1.00']],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $resExtraKeys->assertStatus(422)
            ->assertJsonValidationErrors('items.0');
    }

    /**
     * 3b: Probe POST /api/v1/withdrawals with invalid payloads for structured 422 JSON.
     * Tests both safety gate (unarmed) and schema validation (armed).
     */
    public function test_withdrawals_endpoint_returns_structured_422_on_invalid_payloads(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        // Safety gate check: When financial activation is not armed, returns 422 with policy error
        $resGate = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', []);
        $resGate->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors('policy');

        // Schema validation check: When financial activation is armed
        $this->enableFinancialPosting();

        // Probe 1: Empty body returns field validation errors
        $resEmpty = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', []);
        $resEmpty->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'errors' => ['amount', 'channel', 'destination', 'idempotency_key']]);

        // Probe 2: Negative amount
        $resNeg = $this->postJson('/api/v1/withdrawals', [
            'amount' => '-500.00',
            'channel' => 'bKash',
            'destination' => '01711000000',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $resNeg->assertStatus(422)->assertJsonValidationErrors('amount');

        // Probe 3: Invalid channel
        $resChannel = $this->postJson('/api/v1/withdrawals', [
            'amount' => '500.00',
            'channel' => 'crypto',
            'destination' => '01711000000',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $resChannel->assertStatus(422)->assertJsonValidationErrors('channel');
    }

    /**
     * 3c: Probe POST /api/v1/finance/distribution-preview with invalid payloads for structured 422 JSON.
     */
    public function test_distribution_preview_returns_structured_422_on_invalid_payloads(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        // Probe 1: Empty body
        $resEmpty = $this->actingAs($finance)->postJson('/api/v1/finance/distribution-preview', []);
        $resEmpty->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'errors' => ['account_type', 'mrp', 'discount_percent']]);

        // Probe 2: Out-of-bounds discount percentage (e.g. 10.00% or 65.00%)
        $resLowDiscount = $this->postJson('/api/v1/finance/distribution-preview', [
            'account_type' => 'dealer',
            'mrp' => '1000.00',
            'discount_percent' => '10.00',
        ]);
        $resLowDiscount->assertStatus(422)->assertJsonValidationErrors('calculation');

        $resHighDiscount = $this->postJson('/api/v1/finance/distribution-preview', [
            'account_type' => 'dealer',
            'mrp' => '1000.00',
            'discount_percent' => '65.00',
        ]);
        $resHighDiscount->assertStatus(422)->assertJsonValidationErrors('calculation');

        // Probe 3: Invalid account_type
        $resBadAccount = $this->postJson('/api/v1/finance/distribution-preview', [
            'account_type' => 'retail_customer',
            'mrp' => '1000.00',
            'discount_percent' => '30.00',
        ]);
        $resBadAccount->assertStatus(422)->assertJsonValidationErrors('account_type');
    }

    /**
     * 3d: Probe POST /api/v1/finance/salary-preview with invalid payloads for structured 422 JSON.
     */
    public function test_salary_preview_returns_structured_422_on_invalid_payloads(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        // Probe 1: Empty body
        $resEmpty = $this->actingAs($finance)->postJson('/api/v1/finance/salary-preview', []);
        $resEmpty->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'errors' => ['monthly_purchases']]);

        // Probe 2: Malformed monthly purchases
        $resBad = $this->postJson('/api/v1/finance/salary-preview', [
            'monthly_purchases' => 'not-a-number',
        ]);
        $resBad->assertStatus(422)->assertJsonValidationErrors('monthly_purchases');
    }

    /**
     * 3e: Probe POST /api/v1/inventory/adjustments with invalid payloads for structured 422 JSON.
     */
    public function test_inventory_adjustments_returns_structured_422_on_invalid_payloads(): void
    {
        $this->reasonFields(['stock.adjust' => 'required']);
        $inventory = User::factory()->staff('inventory')->create(['status' => 'active']);

        $resEmpty = $this->actingAs($inventory)->postJson('/api/v1/inventory/adjustments', []);
        $resEmpty->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'errors' => ['product_id', 'quantity', 'reason', 'reference']]);
    }

    /**
     * 3f: Probe POST /api/v1/product-categories with invalid payloads for structured 422 JSON.
     */
    public function test_product_categories_returns_structured_422_on_invalid_payloads(): void
    {
        $staff = User::factory()->staff('super_admin')->create(['status' => 'active']);

        $resEmpty = $this->actingAs($staff)->postJson('/api/v1/product-categories', []);
        $resEmpty->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'errors' => ['name']]);
    }

    /**
     * =========================================================================
     * 4. Authentication and Role Isolation Probes across /api/v1/*
     * =========================================================================
     */

    /**
     * 4a: Unauthenticated guests hitting /api/v1 endpoints receive 401 JSON.
     */
    public function test_unauthenticated_guests_receive_401_json(): void
    {
        $endpoints = [
            ['GET', '/api/v1/me'],
            ['GET', '/api/v1/orders'],
            ['GET', '/api/v1/catalog'],
            ['POST', '/api/v1/orders'],
            ['POST', '/api/v1/withdrawals'],
            ['GET', '/api/v1/finance/defaults'],
            ['POST', '/api/v1/finance/distribution-preview'],
            ['POST', '/api/v1/finance/salary-preview'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $response = $this->json($method, $uri);
            $response->assertStatus(401)
                ->assertHeader('Content-Type', 'application/json')
                ->assertJsonStructure(['message']);
        }
    }

    /**
     * 4b: Non-wholesaler roles attempting to create orders receive 403 Forbidden.
     */
    public function test_non_wholesalers_cannot_create_orders(): void
    {
        $nonWholesalers = ['super_admin', 'finance', 'inventory', 'editor'];
        $product = Product::factory()->create();

        foreach ($nonWholesalers as $role) {
            $user = User::factory()->staff($role)->create(['status' => 'active']);
            $res = $this->actingAs($user)->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $res->assertStatus(403);
        }
    }
}
