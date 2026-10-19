<?php

namespace App\Http\Middleware;

use App\Models\User;
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
        $actor = $request->user();
        if (! $actor?->staff() || $actor->role === 'super_admin' || $request->isMethodSafe()
            || (! $actor->owner() && ! str_starts_with($actor->role, 'custom_') && ! isset($actor->profile['action_permissions']))) {
            return $next($request);
        }
        $route = $request->route();
        $controller = class_basename($route?->getControllerClass() ?? '');
        $method = $route?->getActionMethod();
        if ($controller === 'DataImportController') {
            return $next($request);
        }
        if ($controller === 'AuthController' || $controller === 'CommandController' && $method === 'readNotice') {
            return $next($request);
        }
        if ($controller === 'ProductRegistryController') {
            $action = $method === 'store' ? 'create' : 'edit';
            $required = [];
            if ($method === 'toggleStock') {
                $this->authorize($actor, ['inventory', 'catalog'], 'edit');
            } elseif ($method === 'destroy') {
                $this->authorize($actor, ['catalog'], 'edit');
            } else {
                foreach (['catalog' => ['name', 'sku', 'category', 'description', 'unit', 'active', 'featured', 'image', 'remove_image', 'video_source', 'youtube_url', 'vimeo_url', 'external_video_url', 'video'],
                    'pricing' => ['mrp', 'dealer_price', 'sub_dealer_price', 'cost_price'], 'inventory' => ['low_stock_at', 'initial_stock']] as $module => $fields) {
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
                'minimum' => [['finance'], 'edit'], 'homepageVideo' => [['content', 'catalog'], 'edit'], default => [['staff_admin'], 'edit'],
            },
            'FinanceOperationsController' => match ($method) {
                'prepare' => [['payroll'], 'create'],
                'payrollReview', 'payrollPay', 'scheduleReview', 'reserveReview', 'reservePay', 'release', 'reassignHeld', 'recover', 'openingVerify' => [['finance'], 'approve'],
                'account' => [['finance'], $request->isMethod('PATCH') ? 'edit' : 'create'],
                'contract' => [['finance'], 'edit'], default => [['finance'], 'create'],
            },
            'FieldSalesController' => match ($method) {
                'order' => [['sr_orders'], 'create'], 'application' => [['sr_onboarding'], 'create'],
                'reviewApplication' => [['accounts'], 'approve'], default => [['staff_admin'], 'edit'],
            },
            'CommissionController' => [['finance'], $method === 'updatePolicy' ? 'edit' : 'approve'],
            'FinancialPreviewController' => [['finance'], 'view'],
            'WithdrawalController' => [['finance'], 'approve'],
            'InventoryController' => [['inventory'], 'create'],
            'OrderController' => match ($method) {
                'decision' => [['order_review'], 'approve'], 'verify' => [['finance'], 'approve'],
                'dispatch', 'delivered' => [['inventory'], 'edit'], default => [['orders'], 'create'],
            },
            'CommandController' => match ($method) {
                'account' => [['accounts'], 'create'], 'accountUpdate', 'invite', 'saveDashboardSettings', 'resetDashboardSettings' => [['accounts'], 'edit'],
                'decision' => [['order_review'], 'approve'], 'verify' => [['finance'], 'approve'],
                'dispatch', 'delivered' => [['inventory'], 'edit'], 'stock' => [['inventory'], 'create'],
                'content', 'resetCustomization', 'enquiry' => [['content'], 'edit'], 'customization' => [['content', 'catalog'], 'edit'],
                'policy' => [['finance'], 'create'], 'publishPolicy' => [['finance'], 'approve'], 'updateCommissionRules' => [['finance'], 'edit'],
                'settleSalaryPool', 'settleRankRoyaltyPool', 'claimRankIncentive', 'releaseMaturedCommissions', 'financialGate' => [['finance'], 'approve'],
                'assignAreaDealer', 'removeAreaDealer' => [['accounts'], 'edit'], 'simulateCommissions' => [['finance'], 'view'], default => null,
            },
            default => null,
        };
        abort_unless($permission, 403, 'This action is not enabled for your staff permissions.');
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
        abort(403, 'Your role does not allow this action.');
    }
}
