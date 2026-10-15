<?php

use App\Http\Controllers\Api\V1\Admin\MerchantController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\DanaWebhookController;
use App\Http\Controllers\Api\V1\Internal\MerchantProvisioningController;
use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function (): void {
    Route::post('login', LoginController::class)->middleware('throttle:5,1');
    Route::post('logout', LogoutController::class)->middleware('auth:sanctum');
});

Route::prefix('v1/admin')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::get('merchants', [MerchantController::class, 'index']);
    Route::get('merchants/{merchant}', [MerchantController::class, 'show']);
});

Route::prefix('v1/internal')->group(function (): void {
    Route::get('merchants', [MerchantProvisioningController::class, 'index'])
        ->middleware(['throttle:internal-service', 'internal:merchants:read']);
    Route::get('merchants/{merchant}', [MerchantProvisioningController::class, 'show'])
        ->middleware(['throttle:internal-service', 'internal:merchants:read']);
    Route::post('merchants', [MerchantProvisioningController::class, 'store'])
        ->middleware(['throttle:internal-service', 'internal:merchants:write']);
});

Route::prefix('v1')->middleware(['merchant:payments:read', 'throttle:merchant-api'])->group(function (): void {
    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/{id}', [PaymentController::class, 'show']);
    Route::get('payments/{id}/status', [PaymentController::class, 'status']);
});

Route::post('v1/payments', [PaymentController::class, 'store'])
    ->middleware(['merchant:payments:create', 'throttle:merchant-api']);

Route::post('v1/webhooks/dana', DanaWebhookController::class)
    ->middleware('throttle:60,1');
