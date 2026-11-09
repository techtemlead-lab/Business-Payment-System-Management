<?php

use App\Http\Controllers\OldMoneyAddressController;
use Illuminate\Support\Facades\Route;

/*
 * Old money page addresses, moved to the new Treasury and My Money pages. Loaded inside each portal's
 * signed-in group, before its catch-all route. The query string is kept so saved filters still work.
 */
Route::get('/treasury', [OldMoneyAddressController::class, 'treasury']);
Route::get('/capital-flow', [OldMoneyAddressController::class, 'treasury']);
Route::get('/company-funds', [OldMoneyAddressController::class, 'companyFunds']);
Route::get('/benefits-fund', [OldMoneyAddressController::class, 'benefitsFund']);
Route::get('/ledger', [OldMoneyAddressController::class, 'ledger']);
Route::get('/rewards', [OldMoneyAddressController::class, 'rewards']);
Route::get('/salary', [OldMoneyAddressController::class, 'salary']);
Route::get('/earnings', [OldMoneyAddressController::class, 'myMoney']);
Route::get('/full-report', [OldMoneyAddressController::class, 'myMoney']);
Route::get('/statement', [OldMoneyAddressController::class, 'myMoney']);
Route::get('/payment-receipts', [OldMoneyAddressController::class, 'myMoney']);
Route::get('/withdrawals', [OldMoneyAddressController::class, 'withdrawals']);
Route::get('/accounts/{partner}/statement', [OldMoneyAddressController::class, 'partnerStatement'])->whereNumber('partner');
