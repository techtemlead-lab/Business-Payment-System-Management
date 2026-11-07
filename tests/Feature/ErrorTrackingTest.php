<?php

namespace Tests\Feature;

use App\Support\DeploymentReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sentry\Client;
use Sentry\Event;
use Sentry\State\HubInterface;
use Sentry\Transport\ResultStatus;
use Tests\TestCase;

class ErrorTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_run_without_a_sentry_dsn_and_with_privacy_safe_defaults(): void
    {
        $options = app(HubInterface::class)->getClient()?->getOptions();

        $this->assertNotNull($options);
        $this->assertNull($options->getDsn());
        $this->assertFalse($options->shouldSendDefaultPii());
        $this->assertSame('never', $options->getMaxRequestBodySize());
        $this->assertSame(0.0, $options->getTracesSampleRate());
    }

    public function test_reporting_an_error_without_a_dsn_sends_nothing(): void
    {
        $client = app(HubInterface::class)->getClient();
        $this->assertInstanceOf(Client::class, $client);

        $result = $client->getTransport()->send(Event::createEvent());

        $this->assertEquals(ResultStatus::skipped(), $result->getStatus());
    }

    public function test_readiness_warns_on_production_without_a_dsn_and_clears_once_set(): void
    {
        config(['app.env' => 'production', 'sentry.dsn' => null]);
        $this->assertContains('error_tracking_not_configured', app(DeploymentReadiness::class)->inspect()['warnings']);

        config(['sentry.dsn' => 'https://public@o0.ingest.sentry.io/1']);
        $this->assertNotContains('error_tracking_not_configured', app(DeploymentReadiness::class)->inspect()['warnings']);

        config(['app.env' => 'local', 'sentry.dsn' => null]);
        $this->assertNotContains('error_tracking_not_configured', app(DeploymentReadiness::class)->inspect()['warnings']);
    }
}
