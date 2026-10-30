<?php

namespace Tests\Feature;

use App\Database\Connectors\PostgresConnector;
use App\Support\DatabaseConnectionFailure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

class DatabaseUnavailableErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_connector_applies_connect_timeout_to_dsn_and_pdo(): void
    {
        $connector = new PostgresConnector;
        $config = [
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'madina',
            'charset' => 'utf8',
            'sslmode' => 'prefer',
            'connect_timeout' => 5,
        ];

        $dsn = (new ReflectionMethod(PostgresConnector::class, 'getDsn'))->invoke($connector, $config);
        $options = $connector->getOptions($config);

        $this->assertStringContainsString('connect_timeout=5', $dsn);
        $this->assertStringContainsString('host=127.0.0.1', $dsn);
        $this->assertSame(5, $options[\PDO::ATTR_TIMEOUT]);
    }

    public function test_branded_503_error_page_is_viewport_friendly(): void
    {
        $html = (string) $this->view('errors.503');

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('width=device-width', $html);
        $this->assertStringContainsString(__('site.error_503_heading'), $html);
        $this->assertStringContainsString(__('site.error_back_home'), $html);
        $this->assertStringContainsString('ref-error-page', $html);
    }

    public function test_connection_failures_render_branded_503_instead_of_ignition(): void
    {
        $this->assertTrue(DatabaseConnectionFailure::matches(new PDOException(
            'SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: timeout expired'
        )));

        $exception = new QueryException(
            'pgsql',
            'select 1',
            [],
            new PDOException('SQLSTATE[08006] [7] could not translate host name "db" to address: Name or service not known'),
        );

        $response = app(ExceptionHandler::class)->render(request(), $exception);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString(__('site.error_503_heading'), $response->getContent());
        $this->assertStringContainsString('ref-error-page', $response->getContent());
        $this->assertStringNotContainsString('Ignition', $response->getContent());
    }

    public function test_dealer_login_still_loads_when_database_is_healthy(): void
    {
        $this->onPortal('dealer')
            ->get('/login')
            ->assertOk()
            ->assertSee(__('auth_ui.sign_in'), false);
    }
}
