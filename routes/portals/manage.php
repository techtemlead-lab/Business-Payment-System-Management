<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\IntegrationSettingsController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ProductRegistryController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Portals\Manage\DashboardController;
use Illuminate\Support\Facades\Route;

$registerCommands = require __DIR__.'/commands.php';

Route::post('/locale', [LocaleController::class, 'update'])->middleware('throttle:30,1');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', fn () => view('company.auth', ['mode' => 'login', 'ownerPortal' => false]))->name('company.login');
    Route::get('/login/2fa', [AuthController::class, 'showTotp']);
    Route::get('/forgot-password', fn () => view('company.auth', ['mode' => 'forgot', 'ownerPortal' => false]))->name('company.password.request');
    Route::get('/reset-password/{token}', fn (string $token) => view('company.auth', ['mode' => 'reset', 'token' => $token, 'email' => request('email'), 'ownerPortal' => false]))->name('company.password.reset');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/login/2fa', [AuthController::class, 'verifyTotp'])->middleware('throttle:5,1');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
});

Route::middleware(['auth', 'active', 'portal:manage'])->group(function () use ($registerCommands): void {
    $registerCommands('manage');
    Route::get('/operations/data-imports', [DataImportController::class, 'index'])->name('company.imports');
    Route::get('/operations/service-integrations', [IntegrationSettingsController::class, 'index'])->name('company.integrations');
    Route::get('/operations/{section}', [OperationsController::class, 'index'])->whereIn('section', OperationsController::Sections)->name('company.operations');
    Route::get('/accounts/create', [DashboardController::class, 'createAccount']);
    Route::get('/products/create', [ProductRegistryController::class, 'edit']);
    Route::get('/products/{product}', [ProductRegistryController::class, 'edit']);
    Route::get('/orders/{order}', [DashboardController::class, 'show']);
    Route::get('/orders/{order}/invoice', [DashboardController::class, 'orderInvoice'])->middleware('throttle:20,1');
    Route::get('/orders/{order}/invoice/print', [DashboardController::class, 'orderInvoicePrint'])->middleware('throttle:20,1');
    Route::get('/withdrawals/{id}/receipt', [WithdrawalController::class, 'receipt']);
    Route::get('/withdrawals/{id}', [WithdrawalController::class, 'show']);
    Route::redirect('/places', '/place-codes', 301);
    Route::get('/{section?}', [DashboardController::class, 'index']);
});
