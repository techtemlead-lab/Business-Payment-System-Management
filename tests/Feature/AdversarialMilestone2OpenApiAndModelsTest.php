<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentClaim;
use App\Models\Receipt;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

class AdversarialMilestone2OpenApiAndModelsTest extends TestCase
{
    use DatabaseTransactions;

    // =========================================================================
    // SECTION 1: PROBE OPENAPI SPECIFICATION
    // =========================================================================

    /**
     * Probe 1a: GET /api/v1/openapi.json returns HTTP 200, application/json, and strictly valid JSON.
     */
    public function test_probe_openapi_json_returns_ok_and_valid_json_payload(): void
    {
        $response = $this->get('/api/v1/openapi.json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');

        $rawContent = $response->getContent();
        $this->assertNotEmpty($rawContent);

        // Verify strictly valid JSON
        $decoded = json_decode($rawContent, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'OpenAPI payload must be valid JSON: '.json_last_error_msg());
        $this->assertIsArray($decoded);
    }

    /**
     * Probe 1b: Structural schema validation against OpenAPI 3.0.3 specification rules.
     */
    public function test_probe_openapi_specification_structural_rules(): void
    {
        $response = $this->get('/api/v1/openapi.json');
        $spec = $response->json();

        // 1. Root requirements
        $this->assertSame('3.0.3', $spec['openapi'] ?? null);
        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('paths', $spec);

        // 2. Info object requirements
        $info = $spec['info'];
        $this->assertNotEmpty($info['title'] ?? null);
        $this->assertNotEmpty($info['version'] ?? null);
        $this->assertNotEmpty($info['description'] ?? null);
        $this->assertNotEmpty($info['contact']['email'] ?? null);

        // 3. Components & Schemas
        $this->assertArrayHasKey('components', $spec);
        $schemas = $spec['components']['schemas'] ?? [];
        $this->assertArrayHasKey('Order', $schemas);
        $this->assertArrayHasKey('Withdrawal', $schemas);
        $this->assertArrayHasKey('ErrorResponse', $schemas);

        // Verify Order schema properties
        $orderProps = $schemas['Order']['properties'] ?? [];
        $this->assertArrayHasKey('id', $orderProps);
        $this->assertSame('uuid', $orderProps['id']['format'] ?? null);
        $this->assertArrayHasKey('number', $orderProps);
        $this->assertArrayNotHasKey('order_number', $orderProps);
        $this->assertArrayHasKey('status', $orderProps);
        $this->assertContains('submitted', $orderProps['status']['enum'] ?? []);
        $this->assertNotContains('pending', $orderProps['status']['enum'] ?? []);

        // 4. Paths integrity
        $paths = $spec['paths'];
        $this->assertNotEmpty($paths);

        foreach ($paths as $pathKey => $pathItem) {
            // Rule: Every path must begin with a forward slash
            $this->assertStringStartsWith('/', $pathKey, "Path '{$pathKey}' must start with '/'");

            // Extract path parameter names from template (e.g. {order})
            preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $pathKey, $paramMatches);
            $expectedParams = $paramMatches[1] ?? [];

            foreach ($pathItem as $httpMethod => $operation) {
                if (in_array($httpMethod, ['parameters', 'summary', 'description'], true)) {
                    continue;
                }
                $this->assertContains($httpMethod, ['get', 'post', 'put', 'patch', 'delete', 'options', 'head']);
                $this->assertArrayHasKey('responses', $operation, "Operation {$httpMethod} {$pathKey} must define responses");

                // Verify declared path parameters
                if (! empty($expectedParams)) {
                    $declaredParams = collect($operation['parameters'] ?? [])
                        ->where('in', 'path')
                        ->pluck('name')
                        ->all();
                    foreach ($expectedParams as $expectedParam) {
                        $this->assertContains($expectedParam, $declaredParams, "Path parameter '{$expectedParam}' missing in {$httpMethod} {$pathKey}");
                    }
                }

                // Verify every response code is a valid 3-digit HTTP code (cast to string because PHP json_decode converts numeric keys)
                foreach ($operation['responses'] as $code => $respDef) {
                    $codeStr = (string) $code;
                    $this->assertTrue($codeStr === 'default' || (bool) preg_match('/^[1-5][0-9]{2}$/', $codeStr), "Invalid response code '{$codeStr}' in {$httpMethod} {$pathKey}");
                    $this->assertArrayHasKey('description', $respDef, "Response {$codeStr} in {$httpMethod} {$pathKey} must have description");
                }
            }
        }
    }

    /**
     * Probe 1c: Probing unauthorized, invalid methods, invalid routes, and credential leakage.
     */
    public function test_probe_openapi_access_methods_and_leak_prevention(): void
    {
        // 1. Public unauthenticated access is allowed and intentional (public API docs)
        $unauthResponse = $this->get('/api/v1/openapi.json');
        $unauthResponse->assertOk();

        // 2. Invalid HTTP methods on /api/v1/openapi.json must be rejected with 405 Method Not Allowed
        $postResponse = $this->post('/api/v1/openapi.json');
        $this->assertSame(405, $postResponse->getStatusCode(), 'POST to openapi.json must return 405');

        $putResponse = $this->put('/api/v1/openapi.json');
        $this->assertSame(405, $putResponse->getStatusCode(), 'PUT to openapi.json must return 405');

        $deleteResponse = $this->delete('/api/v1/openapi.json');
        $this->assertSame(405, $deleteResponse->getStatusCode(), 'DELETE to openapi.json must return 405');

        $patchResponse = $this->patch('/api/v1/openapi.json');
        $this->assertSame(405, $patchResponse->getStatusCode(), 'PATCH to openapi.json must return 405');

        // 3. Probing nonexistent documentation routes returns 404
        $this->get('/api/v1/openapi.yaml')->assertNotFound();
        $this->get('/api/v1/openapi')->assertNotFound();
        $this->get('/api/v1/swagger.json')->assertNotFound();

        // 4. Probing credential leakage: spec must NOT contain environment passwords, DB creds, or APP_KEY
        $content = $unauthResponse->getContent();
        $this->assertStringNotContainsString(config('app.key'), $content, 'App key must not leak in OpenAPI spec');
        $this->assertStringNotContainsString('madina_testing', $content, 'Testing DB name must not leak in OpenAPI spec');
        $this->assertStringNotContainsString('postgres', strtolower($content), 'Postgres credentials must not leak in OpenAPI spec');
    }

    /**
     * Probe 1d: Adversarially check documented status codes against actual controller return statements.
     * Surfaces concrete discrepancies between OpenAPI advertised contract and controller execution.
     */
    public function test_probe_documented_status_codes_against_actual_controllers(): void
    {
        $response = $this->get('/api/v1/openapi.json');
        $spec = $response->json();

        // 1. Withdrawals: Documented 201 matches WithdrawalController::store
        $withdrawalPostResponses = $spec['paths']['/api/v1/withdrawals']['post']['responses'] ?? [];
        $this->assertArrayHasKey('201', $withdrawalPostResponses, 'POST /api/v1/withdrawals documents 201 Created');
        $this->assertArrayNotHasKey('200', $withdrawalPostResponses, 'POST /api/v1/withdrawals does NOT document 200');

        // 2. Orders: Documented 201 matches OrderController::store
        $orderPostResponses = $spec['paths']['/api/v1/orders']['post']['responses'] ?? [];
        $this->assertArrayHasKey('201', $orderPostResponses, 'POST /api/v1/orders documents 201 Created');

        // 3. Payment Claim: Check documented code
        $claimPostResponses = $spec['paths']['/api/v1/orders/{order}/payments']['post']['responses'] ?? [];
        $this->assertNotEmpty($claimPostResponses);

        // Documented in OpenApiController: line 147 specifies '200'
        $this->assertArrayHasKey('200', $claimPostResponses, 'OpenAPI currently documents 200 for payment claims');
    }

    /**
     * Probe 1e: Live HTTP verification of Payment Claim endpoint returning 201 Created.
     * Demonstrates the empirical discrepancy: controller returns 201, while OpenAPI spec advertises 200.
     */
    public function test_probe_payment_claim_endpoint_returns_201_created(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
        ]);

        $order = Order::create([
            'number' => 'MN-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
            'user_id' => $dealer->id,
            'status' => 'submitted',
            'channel' => 'dealer',
            'currency' => 'BDT',
            'subtotal' => '1000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '1000.00',
            'paid' => '0.00',
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'claim-test-order'),
        ]);

        $claimPayload = [
            'channel' => 'bKash',
            'reference' => 'BKASH-'.Str::upper(Str::random(8)),
            'amount' => '500.00',
        ];

        $response = $this->actingAs($dealer)->postJson("/api/v1/orders/{$order->id}/payments", $claimPayload);

        // Controller returns 201 Created
        $response->assertStatus(201);
        $this->assertSame(201, $response->getStatusCode(), 'OrderController::claim returns HTTP 201 Created');

        // Verify in database
        $this->assertDatabaseHas('payment_claims', [
            'order_id' => $order->id,
            'channel' => 'bKash',
            'reference' => $claimPayload['reference'],
            'amount' => '500.00',
        ]);
    }

    // =========================================================================
    // SECTION 2: PROBE STANDALONE PSR-4 MODELS
    // =========================================================================

    /**
     * Probe 2a: Standalone PSR-4 models autoload correctly without requiring Order.php parsing.
     */
    public function test_probe_psr4_models_autoload_and_separate_files(): void
    {
        $this->assertTrue(class_exists(PaymentClaim::class));
        $this->assertTrue(class_exists(Receipt::class));
        $this->assertTrue(class_exists(Shipment::class));

        // Verify each model resides in its own distinct file matching PSR-4 standards
        $claimRef = new ReflectionClass(PaymentClaim::class);
        $this->assertStringEndsWith('app/Models/PaymentClaim.php', str_replace('\\', '/', $claimRef->getFileName()));

        $receiptRef = new ReflectionClass(Receipt::class);
        $this->assertStringEndsWith('app/Models/Receipt.php', str_replace('\\', '/', $receiptRef->getFileName()));

        $shipmentRef = new ReflectionClass(Shipment::class);
        $this->assertStringEndsWith('app/Models/Shipment.php', str_replace('\\', '/', $shipmentRef->getFileName()));
    }

    /**
     * Probe 2b: Mass assignment vulnerabilities, guarded attributes, and mutator filtering.
     */
    public function test_probe_mass_assignment_and_guarded_attributes(): void
    {
        // 1. PaymentClaim: $guarded = []
        $claim = new PaymentClaim([
            'order_id' => (string) Str::uuid(),
            'channel' => 'bank',
            'reference' => 'TXN-'.Str::random(8),
            'amount' => '500.00',
            'user_id' => 9999, // Compatibility mutator
        ]);

        $this->assertSame([], $claim->getGuarded());
        // Compatibility mutator must safely drop or ignore user_id from attributes
        $this->assertArrayNotHasKey('user_id', $claim->getAttributes(), 'user_id must not be stored in attributes since column does not exist');

        // 2. Receipt: $guarded = []
        $receipt = new Receipt([
            'order_id' => (string) Str::uuid(),
            'channel' => 'bKash',
            'reference' => 'RCP-'.Str::random(8),
            'amount' => '300.00',
        ]);
        $this->assertSame([], $receipt->getGuarded());
        $this->assertSame('bKash', $receipt->channel);

        // 3. Shipment: $guarded = []
        $shipment = new Shipment([
            'order_id' => (string) Str::uuid(),
            'courier' => 'Sundarban',
            'tracking' => 'TRK-'.Str::random(8),
            'lines' => json_encode([['line_id' => 1, 'qty' => 5]]),
        ]);
        $this->assertSame([], $shipment->getGuarded());
        $this->assertSame('Sundarban', $shipment->courier);

        // 4. Order: $guarded = ['id']
        // Test that mass assigning 'id' to Order is ignored
        $order = new Order(['id' => '00000000-0000-0000-0000-000000000000', 'number' => 'ORD-TEST-001']);
        $this->assertNotSame('00000000-0000-0000-0000-000000000000', $order->id, 'Order id must be guarded against mass assignment');
        $this->assertSame(['id'], $order->getGuarded());
    }

    /**
     * Probe 2c: Database insertion failure when mass-assigning non-existent column under $guarded = [].
     */
    public function test_probe_nonexistent_column_injection_on_guarded_empty(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'number' => 'ORD-M2-'.Str::random(6),
            'user_id' => $user->id,
            'status' => 'submitted',
            'channel' => 'dealer',
            'currency' => 'BDT',
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'm2-order'),
        ]);

        // Attempting to persist arbitrary non-existent column must trigger QueryException
        $this->expectException(QueryException::class);

        PaymentClaim::create([
            'order_id' => $order->id,
            'channel' => 'Nagad',
            'reference' => 'NAGAD-'.Str::random(6),
            'amount' => '100.00',
            'unsupported_injected_column' => 'adversarial_payload',
        ]);
    }

    /**
     * Probe 2d: Relationship traversals, bidirectional integrity, and attribute casting.
     */
    public function test_probe_relationship_traversals_and_attribute_casting(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'number' => 'ORD-REL-'.Str::random(6),
            'user_id' => $user->id,
            'status' => 'submitted',
            'channel' => 'dealer',
            'currency' => 'BDT',
            'subtotal' => '1500.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '1500.00',
            'paid' => '0.00',
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'rel-order'),
            'commission_policy_snapshot' => ['version' => '1.0', 'rules' => []],
        ]);

        $claim = PaymentClaim::create([
            'order_id' => $order->id,
            'channel' => 'Bank',
            'reference' => 'CLM-'.Str::random(6),
            'amount' => '1500.00',
            'status' => 'pending',
        ]);

        $receipt = Receipt::create([
            'order_id' => $order->id,
            'claim_id' => $claim->id,
            'channel' => 'Bank',
            'reference' => 'RCP-'.Str::random(6),
            'amount' => '1500.00',
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier' => 'Steadfast',
            'tracking' => 'STF-'.Str::random(6),
            'status' => 'dispatched',
            'lines' => json_encode([['item' => 'Soap', 'qty' => 10]]),
            'actor_id' => $user->id,
        ]);

        // 1. Upward traversals to Order
        $this->assertInstanceOf(Order::class, $claim->order);
        $this->assertSame($order->id, $claim->order->id);

        $this->assertInstanceOf(Order::class, $receipt->order);
        $this->assertSame($order->id, $receipt->order->id);

        $this->assertInstanceOf(Order::class, $shipment->order);
        $this->assertSame($order->id, $shipment->order->id);

        // 2. Downward traversals from Order
        $this->assertCount(1, $order->paymentClaims);
        $this->assertSame($claim->id, $order->paymentClaims->first()->id);

        $this->assertCount(1, $order->receipts);
        $this->assertSame($receipt->id, $order->receipts->first()->id);

        $this->assertCount(1, $order->shipments);
        $this->assertSame($shipment->id, $order->shipments->first()->id);

        // 3. Null safe traversals on unpersisted instances
        $orphanClaim = new PaymentClaim;
        $this->assertNull($orphanClaim->order);

        $orphanReceipt = new Receipt;
        $this->assertNull($orphanReceipt->order);

        $orphanShipment = new Shipment;
        $this->assertNull($orphanShipment->order);

        $emptyOrder = new Order;
        $this->assertCount(0, $emptyOrder->paymentClaims);
        $this->assertCount(0, $emptyOrder->receipts);
        $this->assertCount(0, $emptyOrder->shipments);

        // 4. Attribute casting checks
        // Order casts: total, paid, due_credit cast to decimal strings, commission_policy_snapshot to array
        $this->assertIsArray($order->commission_policy_snapshot);
        $this->assertSame('1.0', $order->commission_policy_snapshot['version']);
        $this->assertSame('1500.00', (string) $order->total);

        // Shipment casts: observe that lines is not cast to array on Shipment model (returns raw string)
        $reloadedShipment = Shipment::find($shipment->id);
        $this->assertIsString($reloadedShipment->lines, 'Shipment::lines is currently stored as uncast string in Eloquent');
    }
}
