<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class ForceBuiltAssetsTest extends TestCase
{
    public function test_force_built_assets_ignores_public_hot_file(): void
    {
        $hotPath = public_path('hot');
        $previous = is_file($hotPath) ? file_get_contents($hotPath) : null;
        file_put_contents($hotPath, 'http://127.0.0.1:5173');

        try {
            config(['madina.force_built_assets' => true]);
            (new AppServiceProvider($this->app))->boot();

            $this->assertFalse(Vite::isRunningHot());
            $this->assertStringNotContainsString(
                '127.0.0.1:5173',
                (string) $this->get('/')->getContent(),
            );
            $this->assertStringContainsString(
                '/build/assets/',
                (string) $this->get('/')->getContent(),
            );
        } finally {
            if ($previous === null) {
                @unlink($hotPath);
            } else {
                file_put_contents($hotPath, $previous);
            }
        }
    }

    public function test_without_force_built_assets_public_hot_enables_vite_hmr(): void
    {
        $hotPath = public_path('hot');
        $previous = is_file($hotPath) ? file_get_contents($hotPath) : null;
        file_put_contents($hotPath, 'http://127.0.0.1:5173');

        try {
            config(['madina.force_built_assets' => false]);
            Vite::useHotFile($hotPath);
            (new AppServiceProvider($this->app))->boot();

            $this->assertTrue(Vite::isRunningHot());
        } finally {
            if ($previous === null) {
                @unlink($hotPath);
            } else {
                file_put_contents($hotPath, $previous);
            }
        }
    }
}
