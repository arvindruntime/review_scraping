<?php

use Illuminate\Support\Facades\Route;
// use App\Http\Controllers\Api\ReviewController;

Route::get('/', function () {
    return view('reviews.index');
});

// Route::get('/reviews', [ReviewController::class, 'showForm'])->name('reviews.form');
// Route::post('/reviews', [ReviewController::class, 'handleSearch'])->name('reviews.handle');