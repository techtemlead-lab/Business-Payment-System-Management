<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Portals\Dealer\DashboardController;
use Illuminate\Support\Facades\Route;

$registerCommands = require __DIR__.'/commands.php';

Route::post('/locale', [LocaleController::class, 'update'])->middleware('throttle:30,1');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', fn () => view('reference.auth', ['mode' => 'login', 'portal' => 'dealer']))->name('login');
    Route::get('/login/otp', [AuthController::class, 'showOtp'])->middleware('throttle:30,1');
    Route::get('/forgot-password', fn () => view('reference.auth', ['mode' => 'forgot', 'portal' => 'dealer']))->name('password.request');
    Route::get('/reset-password/{token}', fn (string $token) => view('reference.auth', ['mode' => 'reset', 'token' => $token, 'email' => request('email'), 'portal' => 'dealer']))->name('password.reset');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/login/otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:20,1');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
});

Route::middleware(['auth', 'active', 'portal:dealer'])->group(function () use ($registerCommands): void {
    $registerCommands('dealer');
    Route::get('/orders/{order}', [DashboardController::class, 'show']);
    Route::get('/full-report/pdf', [DashboardController::class, 'fullReportPdf'])->middleware('throttle:10,1');
    Route::get('/withdrawals/{id}/receipt', [WithdrawalController::class, 'receipt']);
    Route::get('/withdrawals/{id}', [WithdrawalController::class, 'show']);
    Route::get('/{section?}', [DashboardController::class, 'index']);
});
