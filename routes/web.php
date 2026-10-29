<?php

use App\Http\Controllers\ProductRegistryController;
use App\Support\PortalHost;
use Illuminate\Support\Facades\Route;

Route::get('/products/{product}/image', [ProductRegistryController::class, 'image'])->name('products.image');
Route::get('/products/{product}/video', [ProductRegistryController::class, 'video'])->name('products.video');

$domain = PortalHost::domain();

Route::domain($domain)->group(base_path('routes/portals/public.php'));
if ($domain === 'localhost') {
    // Artisan serve / Cloud Agent previews often use the loopback IP as the Host header.
    Route::domain('127.0.0.1')->group(base_path('routes/portals/public.php'));
}

foreach (['dealer', 'subdealer', 'manage', 'sadmin'] as $portal) {
    Route::domain($portal.'.'.$domain)->group(base_path('routes/portals/'.$portal.'.php'));
}

require __DIR__.'/backend.php';
