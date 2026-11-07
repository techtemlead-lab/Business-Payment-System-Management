<?php

namespace Tests\Feature;

use App\Support\PortalHost;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteCacheReadinessTest extends TestCase
{
    public function test_every_route_name_is_unique_so_route_cache_can_run(): void
    {
        // The testing environment also loads the loopback preview copies of the public site.
        $this->assertTrue(PortalHost::allowsSharedPreview());

        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn (RoutingRoute $route): ?string => $route->getName())
            // An unnamed route inside a name-prefixed group only carries the prefix ("x."); route:cache
            // gives those a generated name, so only full names have to be unique.
            ->filter(fn (?string $name): bool => $name !== null && ! str_ends_with($name, '.'))
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->all();

        $this->assertSame([], $names, 'route:cache fails when two routes share a name.');
    }

    public function test_public_route_names_point_at_the_main_site_host(): void
    {
        $this->assertSame(PortalHost::domain(), Route::getRoutes()->getByName('products.search')?->getDomain());
        $this->assertSame('127.0.0.1', Route::getRoutes()->getByName('preview-host.127-0-0-1.products.search')?->getDomain());
    }

    public function test_production_entrypoint_caches_routes_with_config_and_views(): void
    {
        $script = (string) file_get_contents(base_path('infra/production-entrypoint.sh'));

        $this->assertStringContainsString('php artisan config:cache', $script);
        $this->assertStringContainsString('php artisan route:cache', $script);
        $this->assertStringContainsString('php artisan view:cache', $script);
    }
}
