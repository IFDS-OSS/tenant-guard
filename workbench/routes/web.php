<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\PostController;
use Workbench\App\Models\Plan;

Route::middleware('tenant')->group(function () {
    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
});

Route::middleware(['tenant', 'tenant.required'])->group(function () {
    Route::get('/tenant-only', fn () => ['ok' => true])->name('tenant-only');
});

// Central: must keep working with no tenant at all.
Route::get('/plans', fn () => ['count' => Plan::count()])->name('plans.index');

// Path-based resolution: /t/{tenant}/posts
Route::middleware('tenant')->prefix('t/{tenant}')->group(function () {
    Route::get('/posts', [PostController::class, 'index'])->name('path.posts.index');
});
