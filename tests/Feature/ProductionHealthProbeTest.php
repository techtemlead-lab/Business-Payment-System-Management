<?php

namespace Tests\Feature;

use App\Support\PortalHost;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

class ProductionHealthProbeTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function hostCases(): array
    {
        return [
            'probe from inside the web container' => ['/up', '127.0.0.1', '127.0.0.1', true],
            'probe using localhost' => ['/up', 'localhost', '127.0.0.1', true],
            'loopback host from the proxy network' => ['/up', '127.0.0.1', '172.18.0.1', false],
            'loopback host on any other page' => ['/login', '127.0.0.1', '127.0.0.1', false],
            'loopback host on a similar path' => ['/up/extra', 'localhost', '127.0.0.1', false],
            'loopback host on the readiness probe' => ['/readyz', '127.0.0.1', '127.0.0.1', false],
            'unknown host on the health path' => ['/up', 'attacker.example', '127.0.0.1', false],
            'unknown host from the proxy network' => ['/up', 'attacker.example', '172.18.0.1', false],
            'real domain' => ['/', 'example.test', '172.18.0.1', true],
            'real portal' => ['/login', 'dealer.example.test', '172.18.0.1', true],
        ];
    }

    #[DataProvider('hostCases')]
    public function test_production_host_checks(string $path, string $host, string $remoteAddress, bool $accepted): void
    {
        $this->assertSame($accepted, $this->hostIsAccepted(Request::create('https://'.$host.$path, server: ['REMOTE_ADDR' => $remoteAddress])));
    }

    public function test_a_forwarded_address_cannot_pass_as_the_health_probe(): void
    {
        $request = Request::create('https://127.0.0.1/up', server: ['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1']);

        $this->assertFalse($this->hostIsAccepted($request));
    }

    /**
     * Applies the host list as the TrustHosts middleware does in production, then reads the host.
     * That middleware is skipped while tests run, so it cannot be exercised through a request.
     */
    private function hostIsAccepted(Request $request): bool
    {
        $this->app['env'] = 'production';
        Request::setTrustedHosts(PortalHost::trustedHostPatterns($request));

        try {
            $request->getHost();
        } catch (SuspiciousOperationException) {
            return false;
        }

        return true;
    }
}
