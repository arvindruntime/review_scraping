<?php

use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Admin\AdminController;
use Illuminate\Support\Facades\Route;

// Public API routes
Route::post('/reviews/scrape', [ReviewController::class, 'scrapeReviews']);
// Route::get('/reviews', [ReviewController::class, 'getReviews']);
Route::post('/get-reviews', [ReviewController::class, 'getReviews']);

Route::get('/google-place-suggestions', [ReviewController::class, 'googlePlaceSuggestions']);
Route::get('/trustpilot-suggestions', [ReviewController::class, 'trustpilotSuggestions']);


// Admin routes
Route::prefix('admin')->group(function () {
    Route::get('/searches', [AdminController::class, 'index']);
    Route::get('/searches/{id}', [AdminController::class, 'show']);
    Route::get('/stats', [AdminController::class, 'stats']);
});

