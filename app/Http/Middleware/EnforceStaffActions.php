<?php

namespace App\Http\Middleware;

use App\Domain\Catalog\ProductFacts;
use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Support\PartnerChat;
use App\Models\User;
use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceStaffActions
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->rejectStaffOperationsOnPartnerHosts($request);

        $actor = $request->user();
        $route = $request->route();
        $controller = class_basename($route?->getControllerClass() ?? '');
        $method = (string) ($route?->getActionMethod() ?? '');
        // SiteVisitController only notes that a public page was opened; it decides itself whose visits count.
        if ($request->isMethodSafe() || $controller === 'AuthController' || $controller === 'LocaleController' || $controller === 'ProductReviewController' || $controller === 'SiteVisitController') {
            return $next($request);
        }
        if (! $actor) {
            return $next($request);
        }
        if (! $actor->staff()) {
            $partnerAllowed = match ($controller) {
                '' => true,
                'CommandController' => in_array($method, [
                    'order', 'claim', 'payFromAccount', 'readNotice', 'readAllNotices', 'partnerSupport',
                    'supportTicket', 'supportTicketReply', 'completeTour', 'restartTour', 'switchWorkspaceMode',
                    'storeSiteFeedback',
                ], true),
                'OrderController' => in_array($method, ['index', 'show', 'store', 'claim'], true),
                'WithdrawalController' => $method === 'store',
                'CommissionController' => in_array($method, ['earnings', 'network', 'networkOrders'], true),
                'CatalogController' => $method === 'index',
                'ReportController' => $method === 'export',
                'FinanceOperationsController' => in_array($method, ['accounts', 'export', 'receipt'], true),
                'PartnerAgreementController' => $method === 'accept',
                'PartnerChatController' => $method === 'send',
                'TrainingController' => $method === 'submitQuiz',
                // Verified prescribers only; PrescriptionAccess checks again.
                'PrescriptionController' => in_array($method, ['storePatient', 'updatePatient', 'store'], true),
                'PrescriptionToolsController' => true,
                'PrescriberPublicToolsController' => true,
                'ClinicRecordsController' => true,
                'PrivacyRequestController' => $method === 'store',
                'PushSubscriptionController' => true,
                // A partner's own customer book; CustomerBook scopes every row to the signed-in partner.
                'CustomerBookController' => true,
                // A partner working in the staff desk; ListViews checks again.
                'ListViewController' => true,
                // A fixed list of read-only actions plus marking your own notices; BulkActions checks each.
                'BulkActionController' => true,
                // Only ever ends the signed-in person's own sessions.
                'DeviceSessionController' => true,
                default => false,
            };
            abort_unless($partnerAllowed, 403, __('portal.err_staff_only_action'));

            return $next($request);
        }
        if ($actor->role === 'super_admin') {
            return $next($request);
        }
        // Each person's own saved filters and columns change no business data; ListViews checks the list.
        if (in_array($controller, ['ListViewController', 'BulkActionController', 'DeviceSessionController'], true)) {
            return $next($request);
        }
        // Owners review possible duplicate accounts; IdentityReviewList checks again. Flag only.
        if ($controller === 'IdentityReviewController' && $actor->owner()) {
            return $next($request);
        }
        // Owners always answer partner chat; other staff answer when the owner nominated them.
        // PartnerChat and ChatRouting check again which partners each person may answer.
        if ($controller === 'PartnerChatController' && ($actor->owner() || ($method === 'reply' && PartnerChat::canAnswer($actor)))) {
            return $next($request);
        }
        if ($controller === 'PrescriberVerificationController' && $actor->owner()) {
            // Owners (Super Admin, MD, Chairman) switch prescribing on or off; the controller checks again.
            return $next($request);
        }
        if ($controller === 'FundSpendingController' && $actor->owner()) {
            // Owners record and approve spends from company funds; FundSpending checks again.
            return $next($request);
        }
        if ($controller === 'FinanceOperationsController' && $method === 'receipt' && $actor->owner()) {
            // Owners check the bill before approving a spend; the controller checks again.
            return $next($request);
        }
        if ($controller === 'PrivacyRequestController' && $method === 'resolve' && $actor->owner()) {
            // Owners answer partners' data requests; the domain checks again.
            return $next($request);
        }
        $prescribing = in_array($controller, ['PrescriptionToolsController', 'PrescriberPublicToolsController', 'ClinicRecordsController'], true)
            || ($controller === 'PrescriptionController' && in_array($method, ['storePatient', 'updatePatient', 'store', 'dispense'], true));
        if ($prescribing && PrescriptionAccess::usesCompanyStock($actor)) {
            // Office accounts switched on for company stock prescribe and dispense; the domain checks again.
            return $next($request);
        }
        if ($controller === 'SiteVisitSettingsController' && $actor->owner()) {
            // Only owners turn the website visitor count on or off; the controller checks again.
            return $next($request);
        }
        if ($controller === 'ReasonFieldsController' && $actor->owner()) {
            // Only owners decide which reason and note boxes are asked; the controller checks again.
            return $next($request);
        }
        if ($controller === 'DataCleanupController' && $actor->owner()) {
            // Only owners delete old login codes, SMS records and closed enquiries; the controller checks again.
            return $next($request);
        }
        if ($controller === 'CommandController' && $method === 'readNotice') {
            return $next($request);
        }
        // Every staff member may close or replay their own guided tour; it only records that
        // person's tour progress. Finance, stock and support staff got a 403 pop-up otherwise.
        if ($controller === 'CommandController' && in_array($method, ['completeTour', 'restartTour'], true)) {
            return $next($request);
        }
        // Everyone may turn phone notifications on or off for their own account.
        if ($controller === 'PushSubscriptionController') {
            return $next($request);
        }
        if ($controller === 'CommandController' && $method === 'benefitControls' && $actor->owner()) {
            // Only owners may grant no-condition benefits; the controller refuses everyone else.
            return $next($request);
        }
        if ($controller === 'CommandController' && $method === 'refund' && $actor->owner()) {
            // The owners decided they may refund a paid order themselves; OrderRefund checks again.
            return $next($request);
        }
        if (in_array($controller, ['TrainingController', 'PartnerEventController', 'SalesKitController', 'DatedSchemeController', 'PartnerContestController'], true) && $actor->owner()) {
            // Owners manage the partner training library and meetings calendar; the controllers check again.
            return $next($request);
        }
        if ($controller === 'CommandController' && in_array($method, ['resetPartnerPassword', 'revealPartnerPassword'], true) && $actor->owner()) {
            // Owners reset and see passwords; PasswordAccess in the controller decides whose.
            return $next($request);
        }
        if ($controller === 'PartnerAgreementController' && $actor->owner()) {
            // The agreement text and its switch belong to the owners; the controller checks it again.
            return $next($request);
        }
        if ($controller === 'ModuleCompletionController' && in_array($method, ['kycBeforeFirstWithdrawal', 'fullPaymentWhenOrdering'], true) && $actor->role === 'super_admin') {
            // These two switches belong to the Super Admin alone; the controller checks again.
            return $next($request);
        }
        if ($controller === 'CourierAreaController' && $actor->owner()) {
            // The courier area page is open to every owner; the controller and CourierAreaMapping check again.
            return $next($request);
        }
        if ($controller === 'BranchController' && in_array($method, ['select', 'viewAll'], true)) {
            // Choosing which branch to look at changes nothing; BranchOperations checks
            // assignment for one branch and canViewAllBranches() for "All branches".
            return $next($request);
        }
        if ($controller === 'BranchController' && $method === 'update' && $actor->owner()) {
            // The owner decided MD and Chairman may edit a branch; BranchOperations::saveBranch checks again.
            return $next($request);
        }
        if ($controller === 'DataImportController') {
            $ok = $actor->canAct('accounts', 'create')
                || $actor->canAct('catalog', 'create')
                || $actor->canAct('finance', 'create')
                || $actor->canAct('finance', 'approve')
                || $actor->canAct('accounts', 'approve');
            abort_unless($ok, 403, __('portal.err_staff_import_denied'));

            return $next($request);
        }
        if ($controller === 'ProductRegistryController') {
            $action = $method === 'store' ? 'create' : 'edit';
            $required = [];
            if ($method === 'toggleStock') {
                $this->authorize($actor, ['inventory', 'catalog'], 'edit');
            } elseif (in_array($method, ['destroy', 'restore', 'featuredOrder'], true)) {
                $this->authorize($actor, ['catalog'], 'edit');
            } else {
                foreach (['catalog' => ['name', 'sku', 'category', 'sub_category', 'strength', 'description', 'unit', 'active', 'featured', 'image', 'image_original', 'restore_original_image', 'remove_image', 'video_source', 'youtube_url', 'vimeo_url', 'external_video_url', 'video', 'product_category_id', 'product_subcategory_id', 'product_unit_id', 'expires_on', 'batch_number', ...ProductFacts::requestFields()],
                    'pricing' => ['mrp', 'dealer_price', 'dealer_discount_pct', 'sub_dealer_price', 'sub_dealer_discount_pct', 'cost_price'], 'inventory' => ['low_stock_at', 'initial_stock']] as $module => $fields) {
                    if (array_intersect(array_keys($request->all()), $fields)) {
                        $required[] = $module;
                    }
                }
                foreach ($required as $module) {
                    $this->authorize($actor, [$module], $action);
                }
                abort_if($required === [], 403);
            }

            return $next($request);
        }
        $permission = match ($controller) {
            'ModuleCompletionController' => match ($method) {
                'adjustment' => [['finance'], 'create'], 'reviewAdjustment' => [['finance'], 'approve'],
                'minimum' => [['finance'], 'edit'], 'autoApproval' => [['finance'], 'edit'], 'homepageVideo' => [['content', 'catalog'], 'edit'], default => [['staff_admin'], 'edit'],
            },
            'FinanceOperationsController' => match ($method) {
                'prepare' => [['payroll'], 'create'],
                'payrollReview', 'payrollPay', 'scheduleReview', 'reserveReview', 'reservePay', 'release', 'reassignHeld', 'recover', 'openingVerify' => [['finance'], 'approve'],
                'account' => [['finance'], $request->isMethod('PATCH') ? 'edit' : 'create'],
                'accounts' => [['finance'], 'view'],
                'contract' => [['finance'], 'edit'], default => [['finance'], 'create'],
            },
            'IdentityReviewController' => [['finance'], 'edit'],
            'FundSpendingController' => match ($method) {
                'review' => [['finance'], 'approve'],
                'storeHead', 'updateHead', 'saveBudget' => [['finance'], 'edit'],
                default => [['finance'], 'create'],
            },
            'FieldSalesController' => match ($method) {
                'order' => [['sr_orders'], 'create'], 'application' => [['sr_onboarding'], 'create'],
                'reviewApplication' => [['accounts'], 'approve'], default => [['staff_admin'], 'edit'],
            },
            'CommissionController' => [['finance'], $method === 'updatePolicy' ? 'edit' : 'approve'],
            'FinancialPreviewController' => [['finance'], 'view'],
            'WithdrawalController' => [['finance'], 'approve'],
            'InventoryController' => [['inventory'], 'create'],
            'StockLotController' => [['inventory'], $method === 'requestTransfer' ? 'create' : 'edit'],
            'OrderController' => match ($method) {
                'decision' => [['order_review'], 'approve'], 'verify' => [['finance'], 'approve'],
                'dispatch', 'delivered' => [['inventory'], 'edit'], default => [['orders'], 'create'],
            },
            'CommandController' => match ($method) {
                'account' => [['accounts'], 'create'], 'accountUpdate', 'invite', 'resetPartnerPassword', 'revealPartnerPassword', 'saveDashboardSettings', 'resetDashboardSettings' => [['accounts'], 'edit'],
                'storeKycDocument' => [['accounts', 'identity_write'], 'create'],
                'benefitControls' => [['finance'], 'approve'],
                'decision' => [['order_review'], 'approve'], 'verify', 'refund' => [['finance'], 'approve'],
                'dispatch', 'delivered', 'supplyDate' => [['inventory'], 'edit'], 'stock' => [['inventory'], 'create'],
                'content', 'resetCustomization' => [['content'], 'edit'],
                'enquiry' => [['content', 'accounts', 'support_desk'], 'edit'],
                'moderateSiteFeedback', 'storeStaffTestimonial' => [['content'], 'edit'],
                'customization', 'uploadCategoryIcon' => [['content', 'catalog'], 'edit'],
                'policy' => [['finance'], 'create'], 'publishPolicy' => [['finance'], 'approve'], 'updateCommissionRules' => [['finance'], 'edit'],
                'settleSalaryPool', 'settleRankRoyaltyPool', 'claimRankIncentive', 'releaseMaturedCommissions', 'financialGate' => [['finance'], 'approve'],
                'assignAreaDealer', 'removeAreaDealer' => [['accounts'], 'edit'], 'simulateCommissions' => [['finance'], 'view'],
                'order', 'claim' => [['orders', 'sr_orders'], 'create'],
                'supportTicket', 'supportTicketReply' => [['accounts', 'content', 'support_desk'], 'edit'],
                'linkStaffRole', 'unlinkStaffRole' => [['accounts'], 'edit'],
                default => null,
            },
            // Courier parcels: change courier, try a refused booking again, mark picked up.
            'CourierShipmentController' => [['inventory', 'order_review'], 'edit'],
            'TrainingController', 'PartnerEventController' => [['content'], $method === 'store' ? 'create' : 'edit'],
            'IntegrationSettingsController' => [['finance'], 'edit'],
            'IdentityLookupController' => [['accounts'], 'view'],
            'PlaceCodeController' => [['accounts'], match ($method) {
                'store' => 'create',
                'destroy' => 'edit',
                default => 'edit',
            }],
            'BranchController' => match ($method) {
                'index', 'deliveryPoints' => [['inventory', 'accounts'], 'view'],
                'select' => [['inventory', 'accounts', 'orders'], 'view'],
                'assignStaff', 'removeStaff' => [['inventory', 'accounts'], 'edit'],
                default => [['inventory', 'accounts'], $method === 'store' ? 'create' : 'edit'],
            },
            'ProductCategoryController' => match ($method) {
                'index' => [['catalog', 'pricing', 'inventory'], 'view'],
                'store' => [['catalog'], 'create'],
                default => [['catalog'], 'edit'],
            },
            'ProductSubcategoryController' => match ($method) {
                'store' => [['catalog'], 'create'],
                default => [['catalog'], 'edit'],
            },
            'ProductConcernController' => [['catalog'], $method === 'store' ? 'create' : 'edit'],
            'ProductSizeGroupController' => [['catalog'], 'edit'],
            'ProductUnitController' => match ($method) {
                'index' => [['catalog', 'pricing', 'inventory'], 'view'],
                'store' => [['catalog'], 'create'],
                default => [['catalog'], 'edit'],
            },
            'CatalogController' => [['catalog', 'pricing', 'inventory', 'orders', 'finance'], 'view'],
            'PartnerChatController' => [['partner_chat'], 'create'],
            'PrescriberVerificationController' => [['prescriber_verify'], 'edit'],
            'PublicTrustAdminController' => [['content'], 'edit'],
            'AccountingController' => [['finance'], $method === 'saveApprovalLimit' ? 'edit' : 'create'],
            'PurchasingController' => [['inventory'], in_array($method, ['updateSupplier', 'archiveSupplier', 'restoreSupplier', 'sendOrder', 'cancelOrder'], true) ? 'edit' : 'create'],
            'StockCountController' => [['inventory'], match ($method) {
                'approve', 'reject' => 'approve',
                'cancel' => 'edit',
                default => 'create',
            }],
            default => null,
        };
        abort_unless($permission, 403, __('portal.err_staff_perm_disabled'));
        $this->authorize($actor, $permission[0], $permission[1]);

        return $next($request);
    }

    /** @param list<string> $modules */
    private function authorize(User $actor, array $modules, string $action): void
    {
        foreach ($modules as $module) {
            if ($actor->canAct($module, $action)) {
                return;
            }
        }
        abort(403, __('portal.err_staff_action_denied'));
    }

    private function rejectStaffOperationsOnPartnerHosts(Request $request): void
    {
        if (! $request->is('commands/operations', 'commands/operations/*', 'api/v1/operations', 'api/v1/operations/*')) {
            return;
        }

        $portal = PortalHost::current($request);
        if (in_array($portal, ['manage', 'sadmin'], true)) {
            return;
        }

        if ($request->isMethod('GET') && $request->is(
            'commands/operations/payment-accounts',
            'api/v1/operations/payment-accounts',
            'commands/operations/reports',
            'api/v1/operations/reports',
            'commands/operations/receipts/*',
            'api/v1/operations/receipts/*',
        )) {
            return;
        }

        abort(404);
    }
}
