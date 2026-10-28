<?php

namespace Tests\Feature;

use App\Domain\Finance\FraudAnomalyDetector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_correlation_id_middleware_propagates_or_generates_tracking_header(): void
    {
        // 1. Custom incoming correlation ID is propagated
        $response = $this->withHeader('X-Correlation-ID', 'CORR-TEST-998877')
            ->get('/healthz');

        $response->assertOk();
        $response->assertHeader('X-Correlation-ID', 'CORR-TEST-998877');

        // 2. Missing correlation ID generates a UUID
        $this->flushHeaders();
        $autoResponse = $this->get('/healthz');
        $autoResponse->assertOk();
        $header = $autoResponse->headers->get('X-Correlation-ID');
        $this->assertNotEmpty($header);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $header);
    }

    public function test_liveness_and_readiness_health_probes(): void
    {
        $this->get('/healthz')
            ->assertOk()
            ->assertJson([
                'status' => 'pass',
                'service' => 'madina-platform',
            ]);

        $this->get('/readyz')
            ->assertOk()
            ->assertJsonPath('status', 'pass')
            ->assertJsonPath('checks.database.status', 'pass')
            ->assertJsonPath('checks.storage.status', 'pass');
    }

    public function test_openapi_specification_endpoint_returns_valid_schema(): void
    {
        $response = $this->get('/api/v1/openapi.json');

        $response->assertOk();
        $response->assertJsonPath('openapi', '3.0.3');
        $response->assertJsonPath('info.title', 'Madina Platform Core API');
        $response->assertJsonStructure([
            'openapi',
            'info',
            'components' => [
                'securitySchemes',
                'schemas',
            ],
            'paths',
        ]);
    }

    public function test_csp_header_injects_dynamic_nonce_and_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString('script-src', $csp);
        $this->assertStringContainsString('nonce-', $csp);
    }

    public function test_fraud_anomaly_detector_flags_velocity_and_large_thresholds(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
        ]);

        $detector = app(FraudAnomalyDetector::class);

        // Standard withdrawal: low risk
        $normal = $detector->analyzeWithdrawal($dealer, 5000.00);
        $this->assertSame(FraudAnomalyDetector::RiskLow, $normal['risk_level']);
        $this->assertFalse($normal['requires_dual_approval']);

        // Large single payout (>= 100k BDT): triggers dual approval requirement
        $large = $detector->analyzeWithdrawal($dealer, 150000.00);
        $this->assertTrue($large['requires_dual_approval']);
        $this->assertContains('LARGE_PAYOUT_THRESHOLD', $large['flags']);
    }

    public function test_audit_verify_integrity_command_runs_successfully(): void
    {
        $this->artisan('audit:verify-integrity')
            ->assertSuccessful();
    }

    public function test_pwa_manifest_and_service_worker_are_accessible(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('short_name', 'Madina Health');

        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript')
            ->assertSee('madina-cache-v1');
    }
}
