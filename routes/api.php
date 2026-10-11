<?php

use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['merchant:payments:read', 'throttle:merchant-api'])->group(function (): void {
    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/{id}', [PaymentController::class, 'show']);
    Route::get('payments/{id}/status', [PaymentController::class, 'status']);
});

Route::post('v1/payments', [PaymentController::class, 'store'])
    ->middleware(['merchant:payments:create', 'throttle:merchant-api']);
