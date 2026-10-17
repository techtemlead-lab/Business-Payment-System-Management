<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CommissionController;
use App\Http\Controllers\Api\V1\FinancialPreviewController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\FieldSalesController;
use App\Http\Controllers\FinanceOperationsController;
use App\Http\Controllers\ModuleCompletionController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\WithdrawalController;
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
        'capabilities' => $request->user()->capabilities(),
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
    Route::post('/withdrawals', [WithdrawalController::class, 'store'])->name('withdrawals.store');
    Route::get('/finance/defaults', [FinancialPreviewController::class, 'defaults'])->name('finance.defaults');
    Route::post('/finance/distribution-preview', [FinancialPreviewController::class, 'distribution'])->name('finance.distribution-preview');
    Route::post('/finance/salary-preview', [FinancialPreviewController::class, 'salary'])->name('finance.salary-preview');
    Route::get('/earnings', [CommissionController::class, 'earnings'])->name('earnings');
    Route::get('/network', [CommissionController::class, 'network'])->name('network');
    Route::get('/network/{user}/orders', [CommissionController::class, 'networkOrders'])->name('network.orders');
    Route::get('/finance/policy', [CommissionController::class, 'policy'])->name('finance.policy');
    Route::patch('/finance/policy', [CommissionController::class, 'updatePolicy'])->name('finance.policy.update');
    Route::post('/finance/orders/{order}/commissions', [CommissionController::class, 'postOrder'])->name('finance.orders.commissions');
    Route::post('/finance/orders/{order}/commission-refunds', [CommissionController::class, 'refund'])->name('finance.orders.refund');
    Route::post('/finance/settlements', [CommissionController::class, 'settle'])->name('finance.settlements');
});

/** Both the Inertia screens and independently developed clients use these services. */
$operations = function (): void {
    $completion = ModuleCompletionController::class;
    Route::post('/orders/{order}/due-adjustments', [$completion, 'adjustment']);
    Route::post('/due-adjustments/{id}/review', [$completion, 'reviewAdjustment'])->whereNumber('id');
    Route::post('/settings/withdrawal-minimum', [$completion, 'minimum']);
    Route::post('/staff/roles', [$completion, 'role']);
    Route::post('/staff/action-permissions', [$completion, 'staffAccess']);
    Route::post('/marketing/homepage-video', [$completion, 'homepageVideo']);
    $finance = FinanceOperationsController::class;
    $sales = FieldSalesController::class;
    Route::get('/payment-accounts', [$finance, 'accounts']);
    Route::post('/payment-accounts', [$finance, 'account']);
    Route::patch('/payment-accounts/{id}', [$finance, 'account'])->whereNumber('id');
    Route::post('/staff/territories', [$sales, 'territory']);
    Route::post('/staff/territories/{id}/remove', [$sales, 'removeTerritory'])->whereNumber('id');
    Route::post('/staff/targets', [$sales, 'target']);
    Route::post('/staff/applications', [$sales, 'application']);
    Route::post('/staff/applications/{id}/review', [$sales, 'reviewApplication'])->whereUuid('id');
    Route::post('/staff/orders', [$sales, 'order']);
    Route::get('/staff/orders/{order}', [$sales, 'show']);
    Route::post('/payroll/contracts', [$finance, 'contract']);
    Route::post('/payroll/prepare', [$finance, 'prepare']);
    Route::post('/payroll/{id}/review', [$finance, 'payrollReview'])->whereUuid('id');
    Route::post('/payroll/{id}/pay', [$finance, 'payrollPay'])->whereUuid('id');
    Route::post('/policy-schedules', [$finance, 'schedule']);
    Route::post('/policy-schedules/{id}/review', [$finance, 'scheduleReview'])->whereNumber('id');
    Route::post('/reserves', [$finance, 'reserve']);
    Route::post('/reserves/{id}/review', [$finance, 'reserveReview'])->whereNumber('id');
    Route::post('/reserves/{id}/pay', [$finance, 'reservePay'])->whereNumber('id');
    Route::post('/reserves/clawbacks/{id}/recover', [$finance, 'recover'])->whereNumber('id');
    Route::post('/holds/{id}/release', [$finance, 'release'])->whereNumber('id');
    Route::post('/opening-balances', [$finance, 'opening']);
    Route::post('/opening-balances/{id}/verify', [$finance, 'openingVerify'])->whereNumber('id');
    Route::get('/receipts/{kind}/{id}', [$finance, 'receipt']);
    Route::get('/reports', [$finance, 'export'])->middleware('throttle:5,1');
    Route::get('/views/{section}', [OperationsController::class, 'index']);
};
Route::prefix('commands/operations')->middleware(['auth', 'active', 'throttle:60,1'])->group($operations);
Route::prefix('api/v1/operations')->middleware(['auth', 'active', BackendJson::class, 'throttle:60,1'])->group($operations);
