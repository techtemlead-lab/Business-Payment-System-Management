<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\FinancialPreviewController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Middleware\BackendJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Same-origin, session-authenticated API for the independently developed frontend.
 * Loaded through web.php: session and CSRF protection remain enabled.
 * All money and percentage values in financial previews are decimal strings.
 */
Route::prefix('api/v1')->name('api.v1.')->middleware(['auth', BackendJson::class, 'throttle:60,1'])->group(function (): void {
    Route::get('/me', fn (Request $request) => response()->json(['data' => [
        'user' => $request->user()->safe(),
        'capabilities' => config('madina.roles.'.$request->user()->role, []),
    ]]))->name('me');
    Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::post('/orders/{order}/decision', [OrderController::class, 'decision'])->name('orders.decision');
    Route::post('/orders/{order}/dispatch', [OrderController::class, 'dispatch'])->name('orders.dispatch');
    Route::post('/orders/{order}/payments', [OrderController::class, 'claim'])->middleware('throttle:10,1')->name('orders.payments');
    Route::post('/orders/{order}/shipments/{shipment}/delivered', [OrderController::class, 'delivered'])->name('orders.delivered');
    Route::post('/claims/{claim}/verify', [OrderController::class, 'verify'])->name('claims.verify');
    Route::post('/inventory/adjustments', [InventoryController::class, 'store'])->name('inventory.adjustments');
    Route::get('/finance/defaults', [FinancialPreviewController::class, 'defaults'])->name('finance.defaults');
    Route::post('/finance/distribution-preview', [FinancialPreviewController::class, 'distribution'])->name('finance.distribution-preview');
    Route::post('/finance/salary-preview', [FinancialPreviewController::class, 'salary'])->name('finance.salary-preview');
});
