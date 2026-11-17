<?php

use App\Http\Controllers\MyMoneyController;
use App\Http\Controllers\PartnerIncomeController;
use Illuminate\Support\Facades\Route;

/* A partner's own money page. Loaded inside the dealer and sub-dealer signed-in groups. */
Route::get('/my-money', [MyMoneyController::class, 'show']);

/* Each part of a partner's income on its own, and its printed statement. */
Route::get('/my-income', [PartnerIncomeController::class, 'show']);
Route::get('/my-income/{part}/pdf', [PartnerIncomeController::class, 'pdf'])->whereAlpha('part')->middleware('throttle:10,1');
