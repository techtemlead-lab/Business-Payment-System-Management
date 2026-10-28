<?php

namespace Tests\Unit;

use App\Http\Controllers\LocaleController;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class LocaleReturnUrlTest extends TestCase
{
    private LocaleController $controller;

    private ReflectionMethod $returnUrlMethod;

    private ReflectionMethod $isMediaMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $config = new Repository([
            'app.url' => 'http://127.0.0.1:8088',
            'madina.portal_domain' => 'localhost',
            'portals.domains.public' => '127.0.0.1:8088',
        ]);
        $container->instance('config', $config);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $this->controller = new LocaleController;
        $this->returnUrlMethod = new ReflectionMethod(LocaleController::class, 'returnUrl');
        $this->returnUrlMethod->setAccessible(true);
        $this->isMediaMethod = new ReflectionMethod(LocaleController::class, 'isMediaOrAssetPath');
        $this->isMediaMethod->setAccessible(true);
    }

    public function test_identifies_image_and_media_paths_correctly(): void
    {
        $this->assertTrue($this->isMediaMethod->invoke($this->controller, '/products/12/image'));
        $this->assertTrue($this->isMediaMethod->invoke($this->controller, '/products/45/video'));
        $this->assertTrue($this->isMediaMethod->invoke($this->controller, '/branding/banner.jpg'));
        $this->assertTrue($this->isMediaMethod->invoke($this->controller, '/storage/product.png'));
        $this->assertTrue($this->isMediaMethod->invoke($this->controller, '/locale'));
        $this->assertTrue($this->isMediaMethod->invoke($this->controller, ''));

        $this->assertFalse($this->isMediaMethod->invoke($this->controller, '/'));
        $this->assertFalse($this->isMediaMethod->invoke($this->controller, '/products'));
        $this->assertFalse($this->isMediaMethod->invoke($this->controller, '/products/12'));
        $this->assertFalse($this->isMediaMethod->invoke($this->controller, '/dealers'));
        $this->assertFalse($this->isMediaMethod->invoke($this->controller, '/about'));
        $this->assertFalse($this->isMediaMethod->invoke($this->controller, '/contact'));
    }

    public function test_return_url_discards_image_urls_and_falls_back_safely(): void
    {
        $request = Request::create('http://127.0.0.1:8088/locale', 'POST', [
            'locale' => 'bn',
            'return_url' => 'http://127.0.0.1:8088/products/12/image?v=2',
        ]);

        $url = $this->returnUrlMethod->invoke($this->controller, $request);

        // Must not be the product image URL, must fall back safely to home root
        $this->assertStringNotContainsString('/products/12/image', $url);
        $this->assertStringEndsWith('/', $url);
    }

    public function test_return_url_accepts_valid_page_urls(): void
    {
        $request = Request::create('http://127.0.0.1:8088/locale', 'POST', [
            'locale' => 'bn',
            'return_url' => 'http://127.0.0.1:8088/products?category=care',
        ]);

        $url = $this->returnUrlMethod->invoke($this->controller, $request);

        $this->assertSame('http://127.0.0.1:8088/products?category=care', $url);
    }
}
