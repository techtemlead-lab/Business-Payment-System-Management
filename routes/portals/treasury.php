<?php

use App\Domain\Finance\FundCatalog;
use App\Http\Controllers\PartnerIncomeController;
use App\Http\Controllers\TreasuryController;
use Illuminate\Support\Facades\Route;

/*
 * Treasury pages. Loaded inside each portal's signed-in group, before its catch-all route. They are
 * on the partner hosts too, for a dealer with a linked office role; the checks in TreasuryAccess
 * decide who gets in.
 */
Route::get('/treasury/funds', [TreasuryController::class, 'funds']);
Route::get('/treasury/funds/{fund}', [TreasuryController::class, 'fund'])->whereIn('fund', FundCatalog::keys());
Route::get('/treasury/orders', [TreasuryController::class, 'orders']);
Route::get('/treasury/orders/{order}', [TreasuryController::class, 'order'])->whereUuid('order');
Route::get('/treasury/events', [TreasuryController::class, 'events']);
Route::get('/treasury/partners', [TreasuryController::class, 'partners']);
Route::get('/treasury/partners/{partner}', [TreasuryController::class, 'partner'])->whereNumber('partner');
Route::get('/treasury/work', [TreasuryController::class, 'work']);
Route::get('/treasury/profit', [TreasuryController::class, 'profit']);
Route::get('/treasury/partners/{partner}/income/{part}/pdf', [PartnerIncomeController::class, 'pdfFor'])->whereNumber('partner')->whereAlpha('part')->middleware('throttle:10,1');
