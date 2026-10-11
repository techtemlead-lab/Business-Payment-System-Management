<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DealerController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\ModeController;
use App\Http\Controllers\NetworkController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => auth()->check() ? redirect('/dashboard') : Inertia::render('Welcome'))->name('home');
Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => Inertia::render('Login'))->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/forgot-password', fn () => Inertia::render('ForgotPassword'))->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', fn (Request $r, string $token) => Inertia::render('ResetPassword', ['token' => $token, 'email' => $r->query('email')]))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
    Route::post('/demo-login', [AuthController::class, 'demo'])->middleware('throttle:10,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/two-factor', fn () => Inertia::render('TwoFactor'));
    Route::post('/two-factor', [AuthController::class, 'verifyMfa'])->middleware('throttle:5,1');
    Route::get('/security', [AuthController::class, 'security']);
    Route::post('/security/setup', [AuthController::class, 'beginMfa'])->middleware('throttle:5,1');
    Route::post('/security/enable', [AuthController::class, 'enableMfa'])->middleware('throttle:5,1');
    Route::post('/confirm-password', [AuthController::class, 'confirm'])->middleware('throttle:5,1');
    Route::middleware('workspace')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');
        Route::get('/dealers', [DealerController::class, 'dealers']);
        Route::post('/dealers', [DealerController::class, 'createDealer']);
        Route::get('/dealers/{dealer}', [DealerController::class, 'showDealer']);
        Route::patch('/dealers/{dealer}', [DealerController::class, 'updateDealer']);
        Route::post('/dealers/{dealer}/status', [DealerController::class, 'statusDealer'])->middleware('throttle:20,1');
        Route::get('/rules', [RuleController::class, 'rules']);
        Route::post('/rules', [RuleController::class, 'saveRule']);
        Route::post('/rules/preview', [RuleController::class, 'preview'])->middleware('throttle:60,1');
        Route::get('/operating-mode', [ModeController::class, 'mode']);
        Route::post('/operating-mode', [ModeController::class, 'changeMode'])->middleware('throttle:10,1');
        Route::post('/ranks', [ModeController::class, 'createRank']);
        Route::post('/ranks/{rank}/approve', [ModeController::class, 'approveRank'])->middleware('throttle:10,1');
        Route::get('/ledger', [LedgerController::class, 'ledger']);
        Route::get('/payouts', [PayoutController::class, 'payouts']);
        Route::post('/payouts', [PayoutController::class, 'requestPayout'])->middleware('throttle:5,1');
        Route::get('/audit', [AuditController::class, 'audit']);
        Route::get('/reports', [ReportController::class, 'reports']);
        Route::get('/reports/export', [ReportController::class, 'export']);
        Route::get('/settings', [SettingsController::class, 'settings']);
        Route::patch('/settings', [SettingsController::class, 'saveSettings']);
        Route::get('/hierarchy', [NetworkController::class, 'hierarchy']);
    });
});
