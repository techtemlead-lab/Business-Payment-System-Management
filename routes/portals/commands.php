<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommandController;
use App\Http\Controllers\IdentityLookupController;
use App\Http\Controllers\PlaceCodeController;
use App\Http\Controllers\ProductRegistryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

/**
 * Authenticated command surface for a portal host.
 * Controllers and portal middleware still enforce role capabilities (403).
 *
 * @return Closure(string): void
 */
return function (string $portal): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/security/password', [AuthController::class, 'password'])->middleware('throttle:5,1');
    Route::post('/commands/notices/{id}/read', [CommandController::class, 'readNotice']);
    Route::post('/commands/owner/contact', [AuthController::class, 'ownerContact'])->middleware('throttle:5,1');

    Route::post('/commands/orders', [CommandController::class, 'order']);
    Route::post('/commands/withdrawals', [WithdrawalController::class, 'store']);
    Route::get('/exports/orders', [ReportController::class, 'export'])->middleware('throttle:5,1');

    Route::post('/commands/orders/{order}/decision', [CommandController::class, 'decision']);
    Route::post('/commands/orders/{order}/dispatch', [CommandController::class, 'dispatch']);
    Route::post('/commands/orders/{order}/payments', [CommandController::class, 'claim'])->middleware('throttle:10,1');
    Route::post('/commands/orders/{order}/shipments/{shipment}/delivered', [CommandController::class, 'delivered']);
    Route::post('/commands/claims/{claim}/verify', [CommandController::class, 'verify']);
    Route::post('/commands/accounts', [CommandController::class, 'account']);
    Route::patch('/commands/accounts/{user}', [CommandController::class, 'accountUpdate']);
    Route::post('/commands/accounts/{user}/benefit-controls', [CommandController::class, 'benefitControls']);
    Route::post('/commands/accounts/{user}/invite', [CommandController::class, 'invite'])->middleware('throttle:3,1');
    Route::post('/commands/identity/bdris-lookup', [IdentityLookupController::class, 'bdris'])->middleware('throttle:20,1');
    Route::get('/commands/identity/place-preview', [IdentityLookupController::class, 'placePreview'])->middleware('throttle:60,1');
    Route::get('/commands/identity/refer-preview', [IdentityLookupController::class, 'referPreview'])->middleware('throttle:60,1');
    Route::get('/commands/place-codes/children', [PlaceCodeController::class, 'children'])->middleware('throttle:60,1');
    Route::post('/commands/products', [ProductRegistryController::class, 'store']);
    Route::patch('/commands/products/{product}', [ProductRegistryController::class, 'update']);
    Route::delete('/commands/products/{product}', [ProductRegistryController::class, 'destroy']);
    Route::post('/commands/products/{product}/toggle-stock', [ProductRegistryController::class, 'toggleStock']);
    Route::post('/commands/stock', [CommandController::class, 'stock']);
    Route::post('/commands/policies', [CommandController::class, 'policy']);
    Route::post('/commands/content', [CommandController::class, 'content']);
    Route::post('/commands/customization', [CommandController::class, 'customization']);
    Route::post('/commands/customization/reset', [CommandController::class, 'resetCustomization']);
    Route::post('/commands/dashboards', [CommandController::class, 'saveDashboardSettings']);
    Route::post('/commands/dashboards/reset', [CommandController::class, 'resetDashboardSettings']);
    Route::post('/commands/enquiries/{id}', [CommandController::class, 'enquiry']);
    Route::post('/commands/withdrawals/{id}/review', [WithdrawalController::class, 'review']);
    Route::post('/commands/withdrawals/{id}/record-payment', [WithdrawalController::class, 'settle']);
    Route::post('/commands/policies/{id}/publish', [CommandController::class, 'publishPolicy']);
    Route::post('/commands/commissions/rules', [CommandController::class, 'updateCommissionRules']);
    Route::post('/commands/commissions/settle-salary', [CommandController::class, 'settleSalaryPool']);
    Route::post('/commands/commissions/settle-rank-royalty', [CommandController::class, 'settleRankRoyaltyPool']);
    Route::post('/commands/commissions/claim-incentive/{user}', [CommandController::class, 'claimRankIncentive']);
    Route::post('/commands/commissions/release-matured', [CommandController::class, 'releaseMaturedCommissions']);
    Route::post('/commands/commissions/simulate', [CommandController::class, 'simulateCommissions']);
    Route::post('/commands/area-dealers', [CommandController::class, 'assignAreaDealer']);
    Route::delete('/commands/area-dealers/{id}', [CommandController::class, 'removeAreaDealer']);
    Route::post('/commands/place-codes', [PlaceCodeController::class, 'store']);
    Route::patch('/commands/place-codes/{placeCode}', [PlaceCodeController::class, 'update']);
};
