<?php

use App\Domain\Lists\ListViews;
use App\Http\Controllers\BulkActionController;
use App\Http\Controllers\DeviceSessionController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\IdentityReviewController;
use App\Http\Controllers\ListViewController;
use Illuminate\Support\Facades\Route;

/**
 * Office productivity tools on every portal host. Each controller checks who may use it.
 */
Route::get('/commands/search', GlobalSearchController::class)->middleware('throttle:40,1');

Route::get('/commands/list-views/{list}', [ListViewController::class, 'index'])->whereIn('list', ListViews::Lists)->middleware('throttle:60,1');
Route::post('/commands/list-views/{list}', [ListViewController::class, 'store'])->whereIn('list', ListViews::Lists)->middleware('throttle:30,1');
Route::put('/commands/list-views/{list}/columns', [ListViewController::class, 'columns'])->whereIn('list', ListViews::Lists)->middleware('throttle:60,1');
Route::delete('/commands/list-views/{list}/{view}', [ListViewController::class, 'destroy'])->whereIn('list', ListViews::Lists)->whereNumber('view')->middleware('throttle:30,1');

Route::get('/commands/identity-review', [IdentityReviewController::class, 'index'])->middleware('throttle:60,1');
Route::post('/commands/identity-review/scan', [IdentityReviewController::class, 'scan'])->middleware('throttle:3,1');
Route::post('/commands/identity-review/{flag}/mark', [IdentityReviewController::class, 'mark'])->whereNumber('flag')->middleware('throttle:60,1');

Route::post('/commands/bulk/{action}', BulkActionController::class)->where('action', '[a-z0-9-]+')->middleware('throttle:20,1');

Route::get('/commands/devices', [DeviceSessionController::class, 'index'])->middleware('throttle:30,1');
Route::post('/commands/devices/{handle}/logout', [DeviceSessionController::class, 'logOut'])->where('handle', '[a-f0-9]{64}')->middleware('throttle:5,1');
