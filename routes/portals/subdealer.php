<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Portals\Subdealer\DashboardController;
use Illuminate\Support\Facades\Route;

$registerCommands = require __DIR__.'/commands.php';

Route::post('/locale', [LocaleController::class, 'update'])->middleware('throttle:30,1');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', fn () => view('reference.auth', ['mode' => 'login', 'portal' => 'subdealer']))->name('subdealer.login');
    Route::get('/forgot-password', fn () => view('reference.auth', ['mode' => 'forgot', 'portal' => 'subdealer']))->name('subdealer.password.request');
    Route::get('/reset-password/{token}', fn (string $token) => view('reference.auth', ['mode' => 'reset', 'token' => $token, 'email' => request('email'), 'portal' => 'subdealer']))->name('subdealer.password.reset');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('subdealer.login.store');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1')->name('subdealer.password.email');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('subdealer.password.update');
});

Route::middleware(['auth', 'active', 'portal:subdealer'])->group(function () use ($registerCommands): void {
    $registerCommands('subdealer');
    Route::get('/orders/{order}', [DashboardController::class, 'show']);
    Route::get('/full-report/pdf', [DashboardController::class, 'fullReportPdf'])->middleware('throttle:10,1');
    Route::get('/withdrawals/{id}/receipt', [WithdrawalController::class, 'receipt']);
    Route::get('/withdrawals/{id}', [WithdrawalController::class, 'show']);
    Route::get('/{section?}', [DashboardController::class, 'index']);
});
