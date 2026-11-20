<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'active.account', 'admin'])
    ->group(function (): void {
        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::post('/categories', [AdminCategoryController::class, 'store']);
        Route::patch('/categories/{category}', [AdminCategoryController::class, 'update']);
        Route::patch('/categories/{category}/status', [AdminCategoryController::class, 'updateStatus']);
    });

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1,auth-register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1,auth-login');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'active.account']);
