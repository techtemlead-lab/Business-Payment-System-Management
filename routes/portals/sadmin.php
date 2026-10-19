<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\IntegrationSettingsController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ProductRegistryController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Portals\Sadmin\DashboardController;
use Illuminate\Support\Facades\Route;

$registerCommands = require __DIR__.'/commands.php';

Route::post('/locale', [LocaleController::class, 'update'])->middleware('throttle:30,1');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', fn () => view('company.auth', ['mode' => 'login', 'ownerPortal' => true]))->name('owner.login');
    Route::get('/forgot-password', fn () => view('company.auth', ['mode' => 'forgot', 'ownerPortal' => true]))->name('owner.password.request');
    Route::get('/reset-password/{token}', fn (string $token) => view('company.auth', ['mode' => 'reset', 'token' => $token, 'email' => request('email'), 'ownerPortal' => true]))->name('owner.password.reset');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
});

Route::middleware(['auth', 'active', 'portal:sadmin'])->group(function () use ($registerCommands): void {
    $registerCommands('sadmin');
    Route::get('/operations/data-imports', [DataImportController::class, 'index']);
    Route::get('/operations/service-integrations', [IntegrationSettingsController::class, 'index']);
    Route::get('/operations/{section}', [OperationsController::class, 'index'])->whereIn('section', OperationsController::Sections);
    Route::get('/accounts/create', [DashboardController::class, 'createAccount']);
    Route::get('/products/create', [ProductRegistryController::class, 'edit']);
    Route::get('/products/{product}', [ProductRegistryController::class, 'edit']);
    Route::get('/orders/{order}', [DashboardController::class, 'show']);
    Route::get('/withdrawals/{id}/receipt', [WithdrawalController::class, 'receipt']);
    Route::get('/withdrawals/{id}', [WithdrawalController::class, 'show']);
    Route::get('/{section?}', [DashboardController::class, 'index']);
});
