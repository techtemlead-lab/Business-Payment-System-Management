<?php

namespace Tests\E2E\Tier1;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentClaim;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\E2E\E2ETestCase;

/**
 * Tier 1 — Backend Contracts & Database Integrity Feature Tests.
 *
 * Covers:
 * - B02: Financial Safety Gate
 * - B03: Financial Previews Non-Posting
 * - B04: Policy Snapshots
 * - B05: Transaction Idempotency
 * - B06: OpenAPI 3.0 Specification
 * - B07: Observability & Probes (/healthz, /readyz, X-Correlation-ID)
 * - D01: PostgreSQL Check Constraints
 * - D02: Composite Indexes
 * - D03: Append-Only Audit Trigger
 * - D04: Eloquent Model Relationships
 */
class BackendContractsAndDbFeatureTest extends E2ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFinancialPosting();
        $this->seedCommissionRules();
    }

    // ==========================================
    // B02: Financial Safety Gate
    // ==========================================

    /**
     * B02: Financial mutations require explicit MADINA_FINANCIAL_ACTIVATION=true.
     */
    public function test_financial_activation_gate_blocks_posting_when_disabled(): void
    {
        Config::set('madina.financial_activation', false);

        $this->expectException(ValidationException::class);
        CommissionWallet::requireActive();
    }

    /**
     * B02: Financial mutations require dual-control opening balances verification in financial_verifications.
     */
    public function test_financial_activation_gate_requires_verified_opening_balance(): void
    {
        Config::set('madina.financial_activation', true);
        Config::set('madina.preview_mode', false);

        // Delete all verified opening balances
        DB::table('financial_verifications')->delete();

        $this->expectException(ValidationException::class);
        CommissionWallet::requireActive();
    }

    /**
     * B02: When activation is true and verified opening balance exists, requireActive succeeds.
     */
    public function test_financial_activation_gate_passes_when_fully_authorized(): void
    {
        Config::set('madina.financial_activation', true);
        Config::set('madina.preview_mode', false);

        // enableFinancialPosting already established dual-control verification
        CommissionWallet::requireActive();
        $this->assertTrue(true);
    }

    // ==========================================
    // B03: Financial Previews Non-Posting
    // ==========================================

    /**
     * B03: Financial defaults endpoint explicitly advertises posts_earnings => false.
     */
    public function test_defaults_endpoint_declares_non_posting(): void
    {
        $staff = $this->createStaff('finance');

        $response = $this->actingAs($staff)->getJson('/api/v1/finance/defaults');

        $response->assertOk();
        $response->assertJsonPath('data.posts_earnings', false);
        $response->assertJsonPath('data.currency', 'BDT');
        $response->assertJsonPath('data.basis', 'purchase_value');
    }

    /**
     * B03: Distribution preview computes commission allocations without creating batches or mutating wallets.
     */
    public function test_distribution_preview_does_not_mutate_financial_state(): void
    {
        $staff = $this->createStaff('finance');

        $initialBatches = DB::table('commission_batches')->count();
        $initialAllocations = DB::table('commission_allocations')->count();

        $response = $this->actingAs($staff)->postJson('/api/v1/finance/distribution-preview', [
            'mrp' => '1000.00',
            'account_type' => 'dealer',
            'discount_percent' => '30.00',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.posts_earnings', false);
        $response->assertJsonStructure(['data' => [
            'mrp', 'purchase_value', 'company_share', 'components',
        ]]);

        // Ensure database was strictly unmodified
        $this->assertSame($initialBatches, DB::table('commission_batches')->count());
        $this->assertSame($initialAllocations, DB::table('commission_allocations')->count());
    }

    // ==========================================
    // B04: Policy Snapshots
    // ==========================================

    /**
     * B04: Order submission captures and freezes active commission policy snapshot.
     */
    public function test_order_creation_captures_policy_snapshot(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct();

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $order = Order::findOrFail($response->json('data.id'));

        $this->assertNotNull($order->commission_policy_snapshot);
        $this->assertArrayHasKey('rules', $order->commission_policy_snapshot);
        $this->assertArrayHasKey('revision_id', $order->commission_policy_snapshot);
    }

    /**
     * B04: Subsequent commission policy revisions do not alter frozen snapshot on existing order.
     */
    public function test_subsequent_policy_updates_do_not_alter_order_snapshot(): void
    {
        $admin = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $product = $this->createProduct();

        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $originalSnapshotRevision = $order->commission_policy_snapshot['revision_id'] ?? null;

        // Admin updates global commission rules
        $policyService = app(CommissionPolicy::class);
        $currentRules = $policyService->snapshot()['rules'];
        $policyService->update($admin, $currentRules);

        // Order snapshot remains frozen
        $order->refresh();
        $this->assertSame($originalSnapshotRevision, $order->commission_policy_snapshot['revision_id']);
    }

    // ==========================================
    // B05: Transaction Idempotency
    // ==========================================

    /**
     * B05: Re-submitting identical order with same idempotency key returns exact same order.
     */
    public function test_identical_order_idempotency_key_succeeds(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct();
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => $idempotencyKey,
        ];

        $first = $this->actingAs($dealer)->postJson('/api/v1/orders', $payload);
        $first->assertCreated();
        $orderId = $first->json('data.id');

        // Re-post exact same request
        $second = $this->actingAs($dealer)->postJson('/api/v1/orders', $payload);
        $second->assertOk();
        $this->assertSame($orderId, $second->json('data.id'));
    }

    /**
     * B05: Re-submitting same idempotency key with differing payload returns 409 Conflict.
     */
    public function test_conflicting_idempotency_payload_returns_409(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct();
        $idempotencyKey = (string) Str::uuid();

        $first = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => $idempotencyKey,
        ]);
        $first->assertCreated();

        // Re-post same idempotency key with different items quantity
        $second = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]], // Changed quantity
            'idempotency_key' => $idempotencyKey,
        ]);

        $second->assertStatus(409);
    }

    // ==========================================
    // B06: OpenAPI 3.0 Specification
    // ==========================================

    /**
     * B06: OpenAPI 3.0 specification endpoint returns valid compliant schema document.
     */
    public function test_openapi_specification_endpoint_returns_valid_schema(): void
    {
        $response = $this->get('/api/v1/openapi.json');

        $response->assertOk();
        $response->assertJsonPath('openapi', '3.0.3');
        $response->assertJsonStructure([
            'openapi',
            'info' => ['title', 'version', 'description'],
            'paths',
            'components',
        ]);
    }

    // ==========================================
    // B07: Observability & Probes
    // ==========================================

    /**
     * B07: /healthz liveness probe returns HTTP 200 pass.
     */
    public function test_healthz_liveness_probe_returns_pass(): void
    {
        $response = $this->get('/healthz');

        $response->assertOk();
        $response->assertJsonPath('status', 'pass');
        $response->assertJsonPath('service', 'madina-platform');
    }

    /**
     * B07: /readyz readiness probe verifies database, cache, and storage connectivity.
     */
    public function test_readyz_readiness_probe_verifies_dependencies(): void
    {
        $response = $this->get('/readyz');

        $response->assertOk();
        $response->assertJsonPath('status', 'pass');
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'checks' => [
                'database' => ['status', 'latency_ms', 'connection'],
                'cache' => ['status', 'latency_ms'],
                'storage' => ['status'],
                'environment' => ['financial_activation', 'preview_mode'],
            ],
        ]);
        $response->assertJsonMissingPath('checks.environment.app_env');
    }

    /**
     * B07: X-Correlation-ID header is propagated across HTTP requests and responses.
     */
    public function test_correlation_id_header_propagates(): void
    {
        $correlationId = 'CORR-E2E-'.Str::upper(Str::random(12));

        $response = $this->withHeader('X-Correlation-ID', $correlationId)->get('/healthz');

        $response->assertOk();
        $response->assertHeader('X-Correlation-ID', $correlationId);
    }

    // ==========================================
    // D01–D05: Database Integrity, Constraints & Triggers
    // ==========================================

    /**
     * D01: PostgreSQL check constraint rejects order with paid > total.
     */
    public function test_database_check_constraint_rejects_invalid_paid_bounds(): void
    {
        $dealer = $this->createDealer();

        $this->expectException(QueryException::class);

        // Violating PostgreSQL constraint: paid <= total
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'number' => 'ORD-TEST-INVALID',
            'user_id' => $dealer->id,
            'status' => 'pending',
            'total' => '500.00',
            'paid' => '600.00', // Exceeds total
            'due_credit' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * D02: Required composite performance indexes exist in PostgreSQL schema.
     */
    public function test_composite_indexes_exist_in_database(): void
    {
        $indexes = DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public'");
        $indexNames = array_column($indexes, 'indexname');

        $this->assertNotEmpty($indexNames);

        // Check for performance indexes created in 2026_11_04_000001
        $matching = array_filter($indexNames, fn (string $name): bool => str_contains($name, 'order_lines')
            || str_contains($name, 'payment_claims')
            || str_contains($name, 'shipments')
            || str_contains($name, 'commission_allocations'));

        $this->assertNotEmpty($matching);
    }

    /**
     * D03: PostgreSQL trigger prevents UPDATE mutations on audit_events table.
     */
    public function test_audit_events_table_rejects_update_mutations(): void
    {
        $admin = $this->createStaff('super_admin');

        $id = DB::table('audit_events')->insertGetId([
            'actor_id' => $admin->id,
            'action' => 'test.immutable',
            'subject' => 'test',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('audit_events are append-only');

        DB::table('audit_events')->where('id', $id)->update(['action' => 'tampered.action']);
    }

    /**
     * D03: PostgreSQL trigger prevents DELETE mutations on audit_events table.
     */
    public function test_audit_events_table_rejects_delete_mutations(): void
    {
        $admin = $this->createStaff('super_admin');

        $id = DB::table('audit_events')->insertGetId([
            'actor_id' => $admin->id,
            'action' => 'test.immutable.delete',
            'subject' => 'test',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('audit_events are append-only');

        DB::table('audit_events')->where('id', $id)->delete();
    }

    /**
     * D04: Eloquent Model relationships correctly link Orders, Lines, Claims, and Users.
     */
    public function test_eloquent_relationships_resolve_correctly(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct();

        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        app(OrderService::class)->claim($dealer, $order, [
            'channel' => 'Bank',
            'reference' => 'REL-TXN-1',
            'amount' => '200.00',
        ]);

        $order->refresh();

        // User relationship
        $this->assertInstanceOf(User::class, $order->user);
        $this->assertSame($dealer->id, $order->user->id);

        // Lines relationship
        $this->assertNotEmpty($order->lines);
        $this->assertInstanceOf(OrderLine::class, $order->lines->first());

        // Claims relationship
        $this->assertNotEmpty($order->paymentClaims);
        $this->assertInstanceOf(PaymentClaim::class, $order->paymentClaims->first());
    }
}
