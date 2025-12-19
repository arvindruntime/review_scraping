<?php

use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Admin\AdminController;
use Illuminate\Support\Facades\Route;

// Public API routes
Route::post('/reviews/fetch', [ReviewController::class, 'fetchReviews']);
Route::get('/reviews', [ReviewController::class, 'getReviews']);

Route::get('/trustpilot/reviews', [ReviewController::class, 'getTrustpilotReviews']);


Route::post('/api/reviews/fetch', [ApiReviewController::class, 'fetch'])
    ->name('api.reviews.fetch');

// Admin routes
Route::prefix('admin')->group(function () {
    Route::get('/searches', [AdminController::class, 'index']);
    Route::get('/searches/{id}', [AdminController::class, 'show']);
    Route::get('/stats', [AdminController::class, 'stats']);
});

