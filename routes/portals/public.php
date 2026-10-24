<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PublicController;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicController::class, 'index']);
Route::get('/products/{product}', [PublicController::class, 'product'])->name('products.show');
Route::get('/product/{product}', fn ($product) => redirect('/products/'.$product, 301));
Route::get('/outlets', function () {
    abort_unless(SiteCustomization::isPageEnabled('dealers'), 404);

    return redirect('/dealers', 301);
});
Route::post('/partnership/apply', [PublicController::class, 'apply'])->middleware('throttle:5,1');
Route::get('/join/{code}', [PublicController::class, 'join'])
    ->where('code', '[A-Za-z0-9._-]{2,40}')
    ->middleware('throttle:60,1');
Route::get('/{page}', [PublicController::class, 'index'])->whereIn('page', ['products', 'dealers', 'about', 'contact', 'partnership', 'services', 'quality', 'faq', 'privacy', 'terms', 'notice', 'circular-training', 'circular-price', 'circular-meeting']);
Route::post('/enquiries', [PublicController::class, 'enquiry'])->middleware('throttle:5,1');
Route::post('/locale', [LocaleController::class, 'update'])->middleware('throttle:30,1');
Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
});

$away = function (string $portal, string $path): RedirectResponse {
    $query = request()->getQueryString();

    return redirect()->away(PortalHost::url($portal, $path).($query ? '?'.$query : ''));
};
Route::get('/login', [PublicController::class, 'partnerLogin']);
Route::get('/partner-login', fn () => redirect('/login', 301));
Route::get('/forgot-password', fn () => $away('dealer', '/forgot-password'));
Route::get('/reset-password/{token}', function (string $token) {
    $email = request()->query('email');
    $path = '/reset-password/'.$token.(is_string($email) && $email !== '' ? '?email='.urlencode($email) : '');

    return redirect()->away(PortalHost::url('dealer', $path));
});
Route::get('/workspace/{path?}', fn (?string $path = null) => $away('dealer', $path ? '/'.$path : '/'))->where('path', '.*');
Route::get('/company/{path?}', fn (?string $path = null) => $away('manage', $path ? '/'.$path : '/'))->where('path', '.*');
Route::get('/owner/{path?}', fn (?string $path = null) => $away('sadmin', $path ? '/'.$path : '/'))->where('path', '.*');
