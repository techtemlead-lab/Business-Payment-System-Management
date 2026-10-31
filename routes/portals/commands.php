<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CommandController;
use App\Http\Controllers\IdentityLookupController;
use App\Http\Controllers\PlaceCodeController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductRegistryController;
use App\Http\Controllers\ProductSubcategoryController;
use App\Http\Controllers\ProductUnitController;
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
    Route::post('/security/totp/begin', [AuthController::class, 'beginTotp'])->middleware('throttle:5,1');
    Route::post('/security/totp/confirm', [AuthController::class, 'confirmTotp'])->middleware('throttle:5,1');
    Route::post('/security/totp/disable', [AuthController::class, 'disableTotp'])->middleware('throttle:5,1');
    Route::post('/security/sessions/revoke-others', [AuthController::class, 'revokeOtherSessions'])->middleware('throttle:5,1');
    Route::post('/security/privacy-export', [AuthController::class, 'privacyExport'])->middleware('throttle:3,1');
    Route::post('/commands/notices/{id}/read', [CommandController::class, 'readNotice']);
    Route::post('/commands/notices/read-all', [CommandController::class, 'readAllNotices'])->middleware('throttle:30,1');
    Route::post('/commands/partner-support', [CommandController::class, 'partnerSupport'])->middleware('throttle:5,1');
    Route::post('/commands/owner/contact', [AuthController::class, 'ownerContact'])->middleware('throttle:5,1');
    Route::post('/commands/profile/photo', [AuthController::class, 'profilePhoto'])->middleware('throttle:10,1');
    Route::post('/commands/owner/partner-login-otp', [AuthController::class, 'partnerLoginOtp'])->middleware('throttle:5,1');

    Route::post('/commands/orders', [CommandController::class, 'order']);
    Route::post('/commands/withdrawals', [WithdrawalController::class, 'store']);
    Route::get('/exports/orders', [ReportController::class, 'export'])->middleware('throttle:5,1');
    Route::get('/exports/genealogy', [ReportController::class, 'genealogy'])->middleware('throttle:5,1');

    Route::post('/commands/orders/{order}/decision', [CommandController::class, 'decision']);
    Route::post('/commands/orders/{order}/dispatch', [CommandController::class, 'dispatch']);
    Route::post('/commands/orders/{order}/payments', [CommandController::class, 'claim'])->middleware('throttle:10,1');
    Route::post('/commands/orders/{order}/shipments/{shipment}/delivered', [CommandController::class, 'delivered']);
    Route::post('/commands/claims/{claim}/verify', [CommandController::class, 'verify']);
    Route::post('/commands/accounts', [CommandController::class, 'account']);
    Route::patch('/commands/accounts/{user}', [CommandController::class, 'accountUpdate']);
    Route::post('/commands/accounts/{user}/benefit-controls', [CommandController::class, 'benefitControls']);
    Route::post('/commands/accounts/{user}/invite', [CommandController::class, 'invite'])->middleware('throttle:3,1');
    Route::get('/commands/accounts/{user}/password-status', [CommandController::class, 'partnerPasswordStatus'])->middleware('throttle:60,1');
    Route::post('/commands/accounts/{user}/reset-password', [CommandController::class, 'resetPartnerPassword'])->middleware('throttle:10,1');
    Route::get('/commands/accounts/{user}/kyc-documents', [CommandController::class, 'kycDocuments'])->middleware('throttle:60,1');
    Route::post('/commands/accounts/{user}/kyc-documents', [CommandController::class, 'storeKycDocument'])->middleware('throttle:20,1');
    Route::get('/commands/accounts/{user}/kyc-documents/{document}', [CommandController::class, 'downloadKycDocument'])->middleware('throttle:60,1');
    Route::post('/commands/identity/bdris-lookup', [IdentityLookupController::class, 'bdris'])->middleware('throttle:20,1');
    Route::get('/commands/identity/place-preview', [IdentityLookupController::class, 'placePreview'])->middleware('throttle:60,1');
    Route::get('/commands/identity/refer-preview', [IdentityLookupController::class, 'referPreview'])->middleware('throttle:60,1');
    Route::get('/commands/place-codes/children', [PlaceCodeController::class, 'children'])->middleware('throttle:60,1');
    Route::post('/commands/products', [ProductRegistryController::class, 'store']);
    Route::patch('/commands/products/{product}', [ProductRegistryController::class, 'update']);
    Route::delete('/commands/products/{product}', [ProductRegistryController::class, 'destroy']);
    Route::post('/commands/products/{product}/toggle-stock', [ProductRegistryController::class, 'toggleStock']);
    Route::post('/commands/product-categories', [ProductCategoryController::class, 'store']);
    Route::patch('/commands/product-categories/{productCategory}', [ProductCategoryController::class, 'update']);
    Route::delete('/commands/product-categories/{productCategory}', [ProductCategoryController::class, 'destroy']);
    Route::post('/commands/product-subcategories', [ProductSubcategoryController::class, 'store']);
    Route::patch('/commands/product-subcategories/{productSubcategory}', [ProductSubcategoryController::class, 'update']);
    Route::delete('/commands/product-subcategories/{productSubcategory}', [ProductSubcategoryController::class, 'destroy']);
    Route::post('/commands/product-units', [ProductUnitController::class, 'store']);
    Route::patch('/commands/product-units/{productUnit}', [ProductUnitController::class, 'update']);
    Route::delete('/commands/product-units/{productUnit}', [ProductUnitController::class, 'destroy']);
    Route::post('/commands/stock', [CommandController::class, 'stock']);
    Route::post('/commands/policies', [CommandController::class, 'policy']);
    Route::post('/commands/content', [CommandController::class, 'content']);
    Route::post('/commands/customization', [CommandController::class, 'customization']);
    Route::post('/commands/customization/category-icon', [CommandController::class, 'uploadCategoryIcon'])->middleware('throttle:30,1');
    Route::post('/commands/customization/reset', [CommandController::class, 'resetCustomization']);
    Route::post('/commands/dashboards', [CommandController::class, 'saveDashboardSettings']);
    Route::post('/commands/dashboards/reset', [CommandController::class, 'resetDashboardSettings']);
    Route::post('/commands/enquiries/{id}', [CommandController::class, 'enquiry']);
    Route::post('/commands/feedback', [CommandController::class, 'storeSiteFeedback'])->middleware('throttle:8,1');
    Route::post('/commands/feedback/{id}', [CommandController::class, 'moderateSiteFeedback']);
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

    Route::post('/commands/support-tickets', [CommandController::class, 'supportTicket'])->middleware('throttle:10,1');
    Route::post('/commands/support-tickets/{id}/reply', [CommandController::class, 'supportTicketReply'])->middleware('throttle:20,1');
    Route::post('/commands/tours/{page}/complete', [CommandController::class, 'completeTour'])->middleware('throttle:30,1');
    Route::post('/commands/tours/restart', [CommandController::class, 'restartTour'])->middleware('throttle:20,1');
    Route::post('/commands/workspace-mode', [CommandController::class, 'switchWorkspaceMode'])->middleware('throttle:30,1');
    Route::post('/commands/accounts/{user}/link-staff', [CommandController::class, 'linkStaffRole']);
    Route::post('/commands/accounts/{user}/unlink-staff', [CommandController::class, 'unlinkStaffRole']);
    Route::post('/commands/place-codes', [PlaceCodeController::class, 'store']);
    Route::patch('/commands/place-codes/{placeCode}', [PlaceCodeController::class, 'update']);
    Route::delete('/commands/place-codes/{placeCode}', [PlaceCodeController::class, 'destroy']);
    Route::post('/commands/branches', [BranchController::class, 'store']);
    Route::patch('/commands/branches/{branch}', [BranchController::class, 'update']);
    Route::post('/commands/branches/{branch}/staff', [BranchController::class, 'assignStaff']);
    Route::delete('/commands/branches/{branch}/staff/{user}', [BranchController::class, 'removeStaff']);
    Route::post('/commands/branches/{branch}/select', [BranchController::class, 'select']);
};
