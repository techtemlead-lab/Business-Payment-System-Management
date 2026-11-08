<?php

use App\Http\Controllers\GlobalSearchController;
use Illuminate\Support\Facades\Route;

/**
 * Office productivity tools on every portal host. Each controller checks who may use it.
 */
Route::get('/commands/search', GlobalSearchController::class)->middleware('throttle:40,1');
