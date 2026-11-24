<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ItemImageController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RentalNegotiationController;
use App\Http\Controllers\Api\RentalRequestController;
use App\Http\Controllers\Api\RentalTransactionHandoverController;
use App\Http\Controllers\Api\RentalTransactionQueryController;
use App\Http\Controllers\Api\RentalTransactionReturnController;
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

Route::middleware(['auth:sanctum', 'active.account'])
    ->group(function (): void {
        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/my/items', [ItemController::class, 'myItems']);
        Route::post('/items', [ItemController::class, 'store']);
        Route::get('/items/{item}', [ItemController::class, 'show']);
        Route::patch('/items/{item}', [ItemController::class, 'update']);
        Route::delete('/items/{item}', [ItemController::class, 'destroy']);
        Route::post('/items/{item}/rental-requests', [RentalRequestController::class, 'store']);
        Route::get('/my/rental-requests', [RentalRequestController::class, 'myRequests']);
        Route::get('/incoming/rental-requests', [RentalRequestController::class, 'incomingRequests']);
        Route::get('/rental-requests/{rentalRequest}', [RentalRequestController::class, 'show']);
        Route::patch('/rental-requests/{rentalRequest}/cancel', [RentalRequestController::class, 'cancel']);
        Route::get('/my/rental-transactions', [RentalTransactionQueryController::class, 'myTransactions']);
        Route::get('/incoming/rental-transactions', [RentalTransactionQueryController::class, 'incomingTransactions']);
        Route::get('/rental-transactions/{transaction}', [RentalTransactionQueryController::class, 'show']);
        Route::get('/rental-transactions/{transaction}/payments', [PaymentController::class, 'index']);
        Route::post('/rental-transactions/{transaction}/payments', [PaymentController::class, 'store']);
        Route::patch('/rental-transactions/{transaction}/payments/{payment}/confirm', [PaymentController::class, 'confirm']);
        Route::patch('/rental-transactions/{transaction}/payments/{payment}/reject', [PaymentController::class, 'reject']);
        Route::get('/rental-transactions/{transaction}/payments/{payment}/proof', [PaymentController::class, 'proof'])
            ->name('api.payments.proof');
        Route::patch(
            '/rental-transactions/{transaction}/handover/owner-confirm',
            [RentalTransactionHandoverController::class, 'confirmOwnerHandover']
        );
        Route::patch(
            '/rental-transactions/{transaction}/handover/borrower-confirm',
            [RentalTransactionHandoverController::class, 'confirmBorrowerReceipt']
        );
        Route::patch(
            '/rental-transactions/{transaction}/return/borrower-confirm',
            [RentalTransactionReturnController::class, 'confirmBorrowerReturn']
        );
        Route::patch(
            '/rental-transactions/{transaction}/return/owner-confirm',
            [RentalTransactionReturnController::class, 'confirmOwnerReturn']
        );
        Route::patch('/rental-requests/{rentalRequest}/negotiations/{negotiation}/accept', [RentalNegotiationController::class, 'accept']);
        Route::patch('/rental-requests/{rentalRequest}/negotiations/{negotiation}/counter', [RentalNegotiationController::class, 'counter']);
        Route::patch('/rental-requests/{rentalRequest}/negotiations/{negotiation}/reject', [RentalNegotiationController::class, 'reject']);
        Route::post('/items/{item}/images', [ItemImageController::class, 'store']);
        Route::delete('/items/{item}/images/{image}', [ItemImageController::class, 'destroy']);
        Route::patch('/items/{item}/images/{image}/primary', [ItemImageController::class, 'setPrimary']);
        Route::get('/items/{item}/images/{image}/file', [ItemImageController::class, 'file'])
            ->name('api.items.images.file');
    });

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1,auth-register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1,auth-login');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'active.account']);
