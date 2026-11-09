<?php

use App\Http\Controllers\MyMoneyController;
use Illuminate\Support\Facades\Route;

/* A partner's own money page. Loaded inside the dealer and sub-dealer signed-in groups. */
Route::get('/my-money', [MyMoneyController::class, 'show']);
