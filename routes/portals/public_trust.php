<?php

use App\Domain\Content\PublicTrustContent;
use App\Http\Controllers\PublicTrustController;
use Illuminate\Support\Facades\Route;

Route::get('/verify-dealer', [PublicTrustController::class, 'verifyDealer'])
    ->middleware('throttle:20,1')
    ->name('public.verify-dealer');
Route::get('/grievance', [PublicTrustController::class, 'grievance'])
    ->middleware('throttle:30,1')
    ->name('public.grievance');
Route::post('/grievance', [PublicTrustController::class, 'storeGrievance'])->middleware('throttle:5,1');
Route::get('/quality-documents', [PublicTrustController::class, 'qualityDocuments'])->name('public.quality-documents');
Route::get('/quality-documents/{document}', [PublicTrustController::class, 'qualityDocumentFile'])
    ->whereNumber('document')
    ->middleware('throttle:60,1')
    ->name('public.quality-documents.file');
Route::get('/articles', [PublicTrustController::class, 'articles'])->name('public.articles');
Route::get('/articles/{article}', [PublicTrustController::class, 'article'])
    ->whereNumber('article')
    ->name('public.articles.show');
Route::get('/{page}', [PublicTrustController::class, 'textPage'])
    ->whereIn('page', array_keys(PublicTrustContent::TextPages))
    ->name('public.trust-text');
