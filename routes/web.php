<?php

use App\Http\Controllers\ProductRegistryController;
use App\Http\Controllers\SharedPreviewPortalController;
use App\Support\PortalHost;
use Illuminate\Support\Facades\Route;

Route::get('/products/{product}/image', [ProductRegistryController::class, 'image'])->name('products.image');
Route::get('/products/{product}/video', [ProductRegistryController::class, 'video'])->name('products.video');

Route::get('/portals', [SharedPreviewPortalController::class, 'index'])->name('preview.portals');
Route::get('/preview-portal/{portal}/{path?}', [SharedPreviewPortalController::class, 'enter'])
    ->whereIn('portal', ['public', 'dealer', 'subdealer', 'manage', 'sadmin'])
    ->where('path', '.*')
    ->name('preview.portal');

$domain = PortalHost::domain();

Route::domain($domain)->group(base_path('routes/portals/public.php'));
if (PortalHost::allowsSharedPreview()) {
    // Artisan serve / Cloud Agent previews often use the loopback IP as the Host header.
    foreach (['127.0.0.1', 'localhost'] as $previewHost) {
        if ($previewHost === $domain) {
            continue;
        }
        Route::domain($previewHost)->group(base_path('routes/portals/public.php'));
    }
}

foreach (['dealer', 'subdealer', 'manage', 'sadmin'] as $portal) {
    Route::domain($portal.'.'.$domain)->group(base_path('routes/portals/'.$portal.'.php'));
}

require __DIR__.'/backend.php';
