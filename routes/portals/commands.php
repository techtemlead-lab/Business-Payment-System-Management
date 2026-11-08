<?php

use App\Http\Controllers\AccountListExportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CommandController;
use App\Http\Controllers\CourierShipmentController;
use App\Http\Controllers\DataCleanupController;
use App\Http\Controllers\IdentityLookupController;
use App\Http\Controllers\PartnerAgreementController;
use App\Http\Controllers\PartnerChatController;
use App\Http\Controllers\PartnerEventController;
use App\Http\Controllers\PlaceCodeController;
use App\Http\Controllers\PrescriberVerificationController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\PrescriptionToolsController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductRegistryController;
use App\Http\Controllers\ProductSubcategoryController;
use App\Http\Controllers\ProductUnitController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesKitController;
use App\Http\Controllers\TrainingController;
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
    Route::get('/commands/notices/latest', [CommandController::class, 'latestNotices'])->middleware('throttle:60,1');
    Route::post('/commands/notices/{id}/read', [CommandController::class, 'readNotice']);
    Route::post('/commands/notices/read-all', [CommandController::class, 'readAllNotices'])->middleware('throttle:30,1');
    Route::post('/commands/push-subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('/commands/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:20,1');
    Route::post('/commands/partner-support', [CommandController::class, 'partnerSupport'])->middleware('throttle:5,1');
    Route::post('/commands/messages', [PartnerChatController::class, 'send'])->middleware('throttle:20,1');
    Route::post('/commands/partner-messages/{user}', [PartnerChatController::class, 'reply'])->middleware('throttle:60,1');
    Route::get('/commands/messages/{message}/photo', [PartnerChatController::class, 'photo'])->middleware('throttle:120,1');
    Route::post('/commands/chat-answerers', [PartnerChatController::class, 'answerers'])->middleware('throttle:10,1');
    Route::post('/commands/owner/contact', [AuthController::class, 'ownerContact'])->middleware('throttle:5,1');
    Route::post('/commands/profile/photo', [AuthController::class, 'profilePhoto'])->middleware('throttle:10,1');
    Route::post('/commands/owner/partner-login-otp', [AuthController::class, 'partnerLoginOtp'])->middleware('throttle:5,1');
    Route::post('/commands/owner/password-reset-notice', [AuthController::class, 'passwordResetNotice'])->middleware('throttle:5,1');
    Route::post('/commands/agreement/publish', [PartnerAgreementController::class, 'publish'])->middleware('throttle:10,1');
    Route::post('/commands/agreement/setting', [PartnerAgreementController::class, 'setting'])->middleware('throttle:10,1');
    Route::post('/commands/agreement/accept', [PartnerAgreementController::class, 'accept'])->middleware('throttle:10,1');

    Route::post('/commands/orders', [CommandController::class, 'order'])->middleware('throttle:10,1');
    Route::post('/commands/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/exports/orders', [ReportController::class, 'export'])->middleware('throttle:5,1');
    Route::get('/exports/genealogy', [ReportController::class, 'genealogy'])->middleware('throttle:5,1');
    Route::get('/exports/accounts', AccountListExportController::class)->middleware('throttle:5,1');

    Route::post('/commands/orders/{order}/decision', [CommandController::class, 'decision']);
    Route::post('/commands/orders/{order}/dispatch', [CommandController::class, 'dispatch']);
    Route::post('/commands/orders/{order}/payments', [CommandController::class, 'claim'])->middleware('throttle:10,1');
    Route::post('/commands/orders/{order}/shipments/{shipment}/delivered', [CommandController::class, 'delivered']);
    Route::post('/commands/orders/{order}/supply-date', [CommandController::class, 'supplyDate'])->middleware('throttle:30,1');
    Route::post('/commands/orders/{order}/courier', [CourierShipmentController::class, 'change'])->middleware('throttle:20,1');
    Route::post('/commands/orders/{order}/shipments/{shipment}/courier-retry', [CourierShipmentController::class, 'retry'])->whereNumber('shipment')->middleware('throttle:20,1');
    Route::post('/commands/orders/{order}/shipments/{shipment}/picked-up', [CourierShipmentController::class, 'pickedUp'])->whereNumber('shipment')->middleware('throttle:30,1');
    Route::post('/commands/orders/{order}/shipments/{shipment}/received-back', [CourierShipmentController::class, 'receivedBack'])->whereNumber('shipment')->middleware('throttle:30,1');
    Route::post('/commands/claims/{claim}/verify', [CommandController::class, 'verify']);
    Route::post('/commands/orders/{order}/refund', [CommandController::class, 'refund'])->middleware('throttle:10,1');
    Route::post('/commands/accounts', [CommandController::class, 'account']);
    Route::patch('/commands/accounts/{user}', [CommandController::class, 'accountUpdate']);
    Route::post('/commands/accounts/{user}/benefit-controls', [CommandController::class, 'benefitControls']);
    Route::post('/commands/accounts/{user}/invite', [CommandController::class, 'invite'])->middleware('throttle:3,1');
    Route::get('/commands/accounts/{user}/password-status', [CommandController::class, 'partnerPasswordStatus'])->middleware('throttle:60,1');
    Route::post('/commands/accounts/{user}/reset-password', [CommandController::class, 'resetPartnerPassword'])->middleware('throttle:10,1');
    Route::post('/commands/accounts/{user}/reveal-password', [CommandController::class, 'revealPartnerPassword'])->middleware('throttle:20,1,password-reveal');
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
    Route::post('/commands/training', [TrainingController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/commands/training/reorder', [TrainingController::class, 'reorder'])->middleware('throttle:60,1');
    Route::post('/commands/training/quiz-limit', [TrainingController::class, 'saveQuizLimit'])->middleware('throttle:10,1');
    Route::patch('/commands/training/{trainingItem}', [TrainingController::class, 'update'])->whereNumber('trainingItem')->middleware('throttle:30,1');
    Route::post('/commands/training/{trainingItem}/publish', [TrainingController::class, 'publish'])->whereNumber('trainingItem');
    Route::delete('/commands/training/{trainingItem}', [TrainingController::class, 'destroy'])->whereNumber('trainingItem');
    Route::get('/training/{trainingItem}/pdf', [TrainingController::class, 'pdf'])->whereNumber('trainingItem')->middleware('throttle:60,1');
    Route::post('/commands/data-cleanup/delete', [DataCleanupController::class, 'destroy'])->middleware('throttle:20,1');
    Route::put('/commands/training/{trainingItem}/quiz', [TrainingController::class, 'saveQuiz'])->whereNumber('trainingItem')->middleware('throttle:30,1');
    Route::post('/commands/training/{trainingItem}/quiz-attempt', [TrainingController::class, 'submitQuiz'])->whereNumber('trainingItem')->middleware('throttle:20,1');
    Route::get('/training/{trainingItem}/certificate', [TrainingController::class, 'certificate'])->whereNumber('trainingItem')->middleware('throttle:10,1');
    Route::get('/training/{trainingItem}/video', [TrainingController::class, 'video'])->whereNumber('trainingItem')->middleware('throttle:240,1');
    Route::post('/commands/events', [PartnerEventController::class, 'store'])->middleware('throttle:30,1');
    Route::patch('/commands/events/{partnerEvent}', [PartnerEventController::class, 'update'])->whereNumber('partnerEvent')->middleware('throttle:30,1');
    Route::post('/commands/events/{partnerEvent}/publish', [PartnerEventController::class, 'publish'])->whereNumber('partnerEvent')->middleware('throttle:20,1');
    Route::post('/commands/events/{partnerEvent}/cancel', [PartnerEventController::class, 'cancel'])->whereNumber('partnerEvent')->middleware('throttle:20,1');
    Route::delete('/commands/events/{partnerEvent}', [PartnerEventController::class, 'destroy'])->whereNumber('partnerEvent');
    Route::post('/commands/customization', [CommandController::class, 'customization']);
    Route::post('/commands/customization/category-icon', [CommandController::class, 'uploadCategoryIcon'])->middleware('throttle:30,1');
    Route::post('/commands/customization/reset', [CommandController::class, 'resetCustomization']);
    Route::post('/commands/dashboards', [CommandController::class, 'saveDashboardSettings']);
    Route::post('/commands/dashboards/reset', [CommandController::class, 'resetDashboardSettings']);
    Route::post('/commands/enquiries/{id}', [CommandController::class, 'enquiry']);
    Route::post('/commands/feedback', [CommandController::class, 'storeSiteFeedback'])->middleware('throttle:8,1');
    Route::post('/commands/feedback/{id}', [CommandController::class, 'moderateSiteFeedback'])->whereNumber('id');
    Route::post('/commands/testimonials', [CommandController::class, 'storeStaffTestimonial'])->middleware('throttle:30,1');
    Route::post('/commands/withdrawals/{id}/review', [WithdrawalController::class, 'review'])->whereUuid('id');
    Route::post('/commands/withdrawals/{id}/record-payment', [WithdrawalController::class, 'settle'])->whereUuid('id');
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
    Route::post('/commands/place-codes/management-referral', [PlaceCodeController::class, 'updateManagementReferral']);
    Route::patch('/commands/place-codes/{placeCode}', [PlaceCodeController::class, 'update']);
    Route::delete('/commands/place-codes/{placeCode}', [PlaceCodeController::class, 'destroy']);
    Route::post('/commands/branches', [BranchController::class, 'store']);
    Route::post('/commands/branches/view-all', [BranchController::class, 'viewAll']);
    Route::delete('/commands/branches/{branch}', [BranchController::class, 'destroy']);
    Route::patch('/commands/branches/{branch}', [BranchController::class, 'update']);
    Route::post('/commands/branches/{branch}/staff', [BranchController::class, 'assignStaff']);
    Route::delete('/commands/branches/{branch}/staff/{user}', [BranchController::class, 'removeStaff']);
    Route::post('/commands/branches/{branch}/select', [BranchController::class, 'select']);

    // Prescriptions: patients and prescriptions for verified prescribers, the owner's clinic dispense,
    // and the per-partner prescribing switch on the accounts page.
    Route::get('/commands/prescriptions/places', [PrescriptionController::class, 'places'])->middleware('throttle:60,1');
    Route::post('/commands/prescriptions/patients', [PrescriptionController::class, 'storePatient'])->middleware('throttle:30,1');
    Route::patch('/commands/prescriptions/patients/{patient}', [PrescriptionController::class, 'updatePatient'])->whereNumber('patient')->middleware('throttle:30,1');
    Route::post('/commands/prescriptions', [PrescriptionController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/commands/prescriptions/{prescription}/dispense', [PrescriptionController::class, 'dispense'])->whereNumber('prescription')->middleware('throttle:20,1');
    Route::get('/commands/prescriptions/patients/options', [PrescriptionController::class, 'patientOptions'])->middleware('throttle:60,1');
    Route::post('/commands/prescriptions/registration', [PrescriptionController::class, 'registration'])->middleware('throttle:10,1');
    Route::post('/commands/prescriptions/templates', [PrescriptionToolsController::class, 'storeTemplate'])->middleware('throttle:30,1');
    Route::delete('/commands/prescriptions/templates/{template}', [PrescriptionToolsController::class, 'destroyTemplate'])->whereNumber('template')->middleware('throttle:30,1');
    Route::post('/commands/prescriptions/{prescription}/follow-up-done', [PrescriptionToolsController::class, 'markFollowUpDone'])->whereNumber('prescription')->middleware('throttle:60,1');
    Route::get('/prescriptions/{prescription}/print', [PrescriptionController::class, 'print'])->whereNumber('prescription')->middleware('throttle:30,1');
    Route::get('/commands/prescribers/office-accounts', [PrescriberVerificationController::class, 'officeAccounts'])->middleware('throttle:60,1');
    Route::get('/commands/accounts/{user}/prescriber', [PrescriberVerificationController::class, 'show'])->middleware('throttle:60,1');
    Route::post('/commands/accounts/{user}/prescriber', [PrescriberVerificationController::class, 'enable'])->middleware('throttle:20,1');
    Route::delete('/commands/accounts/{user}/prescriber', [PrescriberVerificationController::class, 'disable'])->middleware('throttle:20,1');

    // Partner tools.
    Route::post('/commands/sales-kit', [SalesKitController::class, 'store'])->middleware('throttle:30,1');
    Route::delete('/commands/sales-kit/{salesKitItem}', [SalesKitController::class, 'destroy'])->whereNumber('salesKitItem')->middleware('throttle:30,1');
    Route::get('/sales-kit/{salesKitItem}/file', [SalesKitController::class, 'file'])->whereNumber('salesKitItem')->middleware('throttle:120,1');
};
