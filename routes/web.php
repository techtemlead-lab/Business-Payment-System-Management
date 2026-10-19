<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommandController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProductRegistryController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WithdrawalController;
use App\Support\PortalHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/products/{product}/image', [ProductRegistryController::class, 'image'])->name('products.image');
Route::get('/products/{product}/video', [ProductRegistryController::class, 'video'])->name('products.video');

$domain = PortalHost::domain();

Route::domain($domain)->group(function (): void {
    Route::get('/', [PublicController::class, 'index']);
    Route::get('/products/{product}', [PublicController::class, 'product'])->name('products.show');
    Route::get('/product/{product}', fn ($product) => redirect('/products/'.$product, 301));
    Route::get('/outlets', fn () => redirect('/dealers', 301));
    Route::get('/{page}', [PublicController::class, 'index'])->whereIn('page', ['products', 'dealers', 'about', 'contact']);
    Route::post('/enquiries', [PublicController::class, 'enquiry'])->middleware('throttle:5,1');

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
});

$registerPortal = function (string $portal): void {
    $staff = in_array($portal, ['manage', 'sadmin'], true);
    Route::middleware('guest')->group(function () use ($portal, $staff): void {
        $login = Route::get('/login', function () use ($portal, $staff) {
            return $staff
                ? view('company.auth', ['mode' => 'login', 'ownerPortal' => $portal === 'sadmin'])
                : view('reference.auth', ['mode' => 'login', 'portal' => $portal]);
        });
        $forgot = Route::get('/forgot-password', function () use ($portal, $staff) {
            return $staff
                ? view('company.auth', ['mode' => 'forgot', 'ownerPortal' => $portal === 'sadmin'])
                : view('reference.auth', ['mode' => 'forgot', 'portal' => $portal]);
        });
        $reset = Route::get('/reset-password/{token}', function (string $token) use ($portal, $staff) {
            $data = ['mode' => 'reset', 'token' => $token, 'email' => request('email'), 'portal' => $portal, 'ownerPortal' => $portal === 'sadmin'];

            return view($staff ? 'company.auth' : 'reference.auth', $data);
        });
        $names = match ($portal) {
            'dealer' => ['login', 'password.request', 'password.reset'],
            'manage' => ['company.login', 'company.password.request', 'company.password.reset'],
            'sadmin' => ['owner.login', 'owner.password.request', 'owner.password.reset'],
            default => [],
        };
        if ($names !== []) {
            $login->name($names[0]);
            $forgot->name($names[1]);
            $reset->name($names[2]);
        }
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
        Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
        Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
    });

    Route::middleware(['auth', 'active', 'portal:'.$portal])->group(function () use ($portal, $staff): void {
        if ($staff) {
            $imports = Route::get('/operations/data-imports', [DataImportController::class, 'index']);
            $operations = Route::get('/operations/{section}', [OperationsController::class, 'index'])->whereIn('section', OperationsController::Sections);
            if ($portal === 'manage') {
                $operations->name('company.operations');
                $imports->name('company.imports');
            }
            Route::get('/accounts/create', [PortalController::class, 'createAccount']);
            Route::get('/products/create', [ProductRegistryController::class, 'edit']);
            Route::get('/products/{product}', [ProductRegistryController::class, 'edit']);
        }
        Route::get('/orders/{order}', [PortalController::class, 'show']);
        Route::get('/withdrawals/{id}/receipt', [WithdrawalController::class, 'receipt']);
        Route::get('/withdrawals/{id}', [WithdrawalController::class, 'show']);
        Route::get('/{section?}', [PortalController::class, 'index']);
    });
};

foreach (['dealer', 'subdealer', 'manage', 'sadmin'] as $portal) {
    Route::domain($portal.'.'.$domain)->group(function () use ($registerPortal, $portal): void {
        $registerPortal($portal);
    });
}

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/security/password', [AuthController::class, 'password'])->middleware('throttle:5,1');
    Route::get('/exports/orders', [ReportController::class, 'export'])->middleware('throttle:5,1');
    Route::post('/commands/orders', [CommandController::class, 'order']);
    Route::post('/commands/orders/{order}/decision', [CommandController::class, 'decision']);
    Route::post('/commands/orders/{order}/dispatch', [CommandController::class, 'dispatch']);
    Route::post('/commands/orders/{order}/payments', [CommandController::class, 'claim'])->middleware('throttle:10,1');
    Route::post('/commands/orders/{order}/shipments/{shipment}/delivered', [CommandController::class, 'delivered']);
    Route::post('/commands/claims/{claim}/verify', [CommandController::class, 'verify']);
    Route::post('/commands/accounts', [CommandController::class, 'account']);
    Route::patch('/commands/accounts/{user}', [CommandController::class, 'accountUpdate']);
    Route::post('/commands/accounts/{user}/invite', [CommandController::class, 'invite'])->middleware('throttle:3,1');
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
    Route::post('/commands/notices/{id}/read', [CommandController::class, 'readNotice']);
    Route::post('/commands/withdrawals', [WithdrawalController::class, 'store']);
    Route::post('/commands/withdrawals/{id}/review', [WithdrawalController::class, 'review']);
    Route::post('/commands/withdrawals/{id}/record-payment', [WithdrawalController::class, 'settle']);
    Route::post('/commands/policies/{id}/publish', [CommandController::class, 'financialGate']);
    Route::post('/commands/commissions/rules', [CommandController::class, 'updateCommissionRules']);
    Route::post('/commands/commissions/settle-salary', [CommandController::class, 'settleSalaryPool']);
    Route::post('/commands/commissions/settle-rank-royalty', [CommandController::class, 'settleRankRoyaltyPool']);
    Route::post('/commands/commissions/claim-incentive/{user}', [CommandController::class, 'claimRankIncentive']);
    Route::post('/commands/commissions/release-matured', [CommandController::class, 'releaseMaturedCommissions']);
    Route::post('/commands/commissions/simulate', [CommandController::class, 'simulateCommissions']);
    Route::post('/commands/area-dealers', [CommandController::class, 'assignAreaDealer']);
    Route::delete('/commands/area-dealers/{id}', [CommandController::class, 'removeAreaDealer']);
});

require __DIR__.'/backend.php';
