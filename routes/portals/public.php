<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PublicController;
use App\Support\PortalHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicController::class, 'index']);
Route::get('/products/{product}', [PublicController::class, 'product'])->name('products.show');
Route::get('/product/{product}', fn ($product) => redirect('/products/'.$product, 301));
Route::get('/outlets', fn () => redirect('/dealers', 301));
Route::get('/{page}', [PublicController::class, 'index'])->whereIn('page', ['products', 'dealers', 'about', 'contact']);
Route::post('/enquiries', [PublicController::class, 'enquiry'])->middleware('throttle:5,1');
Route::post('/locale', [LocaleController::class, 'update'])->middleware('throttle:30,1');
Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
});

$away = function (string $portal, string $path): RedirectResponse {
    $query = request()->getQueryString();

    return redirect()->away(PortalHost::url($portal, $path).($query ? '?'.$query : ''));
};
Route::get('/login', fn () => $away('dealer', '/login'));
Route::get('/forgot-password', fn () => $away('dealer', '/forgot-password'));
Route::get('/reset-password/{token}', function (string $token) {
    $email = request()->query('email');
    $path = '/reset-password/'.$token.(is_string($email) && $email !== '' ? '?email='.urlencode($email) : '');

    return redirect()->away(PortalHost::url('dealer', $path));
});
Route::get('/workspace/{path?}', fn (?string $path = null) => $away('dealer', $path ? '/'.$path : '/'))->where('path', '.*');
Route::get('/company/{path?}', fn (?string $path = null) => $away('manage', $path ? '/'.$path : '/'))->where('path', '.*');
Route::get('/owner/{path?}', fn (?string $path = null) => $away('sadmin', $path ? '/'.$path : '/'))->where('path', '.*');
