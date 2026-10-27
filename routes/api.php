<?php

use App\Http\Controllers\Api\V1\Admin\MerchantController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\DanaWebhookController;
use App\Http\Controllers\Api\V1\Internal\MerchantManagementController;
use App\Http\Controllers\Api\V1\Internal\MerchantProvisioningController;
use App\Http\Controllers\Api\V1\Internal\PaymentAuditController;
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
    Route::get('merchants/{merchant}/readiness', [MerchantManagementController::class, 'readiness'])
        ->middleware(['throttle:internal-service', 'internal:merchants:readiness', 'internal-https']);
    Route::get('merchants/{merchant}/dana-configuration', [MerchantManagementController::class, 'danaConfiguration'])
        ->middleware(['throttle:internal-service', 'internal:merchants:credentials:read', 'internal-https']);
    Route::put('merchants/{merchant}/dana-configuration', [MerchantManagementController::class, 'configureDanaSandbox'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:credentials:write', 'internal-https']);
    Route::get('merchants/{merchant}/sandbox-tester/validation', [MerchantManagementController::class, 'validateDanaSandboxTester'])
        ->middleware(['throttle:internal-service', 'internal:merchants:credentials:read', 'internal-https']);
    Route::post('merchants/{merchant}/sandbox-tester/uat/create-order-missing-timestamp', [MerchantManagementController::class, 'testCreateOrderMissingTimestamp'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:credentials:write', 'internal-https']);
    Route::post('merchants/{merchant}/sandbox-tester/uat/create-order-invalid-amount-format', [MerchantManagementController::class, 'testCreateOrderInvalidAmountFormat'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:credentials:write', 'internal-https']);
    Route::post('merchants/{merchant}/sandbox-tester/uat/create-order-inconsistent-request', [MerchantManagementController::class, 'testCreateOrderInconsistentRequest'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:credentials:write', 'internal-https']);
    Route::post('merchants/{merchant}/sandbox-tester/uat/debit-status-transaction-not-found', [MerchantManagementController::class, 'testDebitStatusTransactionNotFound'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:credentials:write', 'internal-https']);
    Route::post('merchants/{merchant}/sandbox-tester/uat/debit-status-missing-timestamp', [MerchantManagementController::class, 'testDebitStatusMissingTimestamp'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:credentials:write', 'internal-https']);
    Route::post('merchants/{merchant}/sandbox-tester/uat/debit-status-internal-server-error', [MerchantManagementController::class, 'testDebitStatusInternalServerError'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:credentials:write', 'internal-https']);
    Route::post('merchants/{merchant}/sandbox-tester/uat/arm-webhook-500', [MerchantManagementController::class, 'armDanaSandboxWebhookSimulated500'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:credentials:write', 'internal-https']);
    Route::get('merchants/{merchant}/sandbox-tester/transactions', [MerchantManagementController::class, 'danaSandboxTestHistory'])
        ->middleware(['throttle:internal-service', 'internal:merchants:credentials:read', 'internal-https']);
    Route::post('merchants/{merchant}/sandbox-tester/transactions', [MerchantManagementController::class, 'createDanaSandboxTestTransaction'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:credentials:write', 'internal-https']);
    Route::get('merchants/{merchant}/sandbox-tester/transactions/{paymentId}/status', [MerchantManagementController::class, 'danaSandboxTestTransactionStatus'])
        ->whereUlid('paymentId')
        ->middleware(['throttle:internal-service', 'internal:merchants:credentials:read', 'internal-https']);
    Route::get('merchants/{merchant}/sandbox-tester/transactions/{paymentId}/dana-status', [MerchantManagementController::class, 'queryDanaSandboxPaymentStatus'])
        ->whereUlid('paymentId')
        ->middleware(['throttle:internal-service', 'internal:merchants:credentials:read', 'internal-https']);
    Route::get('merchants/{merchant}/payments/{paymentId}/audit', [PaymentAuditController::class, 'show'])
        ->whereUlid('paymentId')
        ->middleware(['throttle:internal-service', 'internal:payments:audit:read', 'internal-https']);
    Route::get('merchants/{merchant}/payments/{paymentId}/webhooks', [PaymentAuditController::class, 'webhooks'])
        ->whereUlid('paymentId')
        ->middleware(['throttle:internal-service', 'internal:payments:audit:read', 'internal-https']);
    Route::get('merchants/{merchant}/payment-audit', [PaymentAuditController::class, 'index'])
        ->middleware(['throttle:internal-service', 'internal:payments:audit:read', 'internal-https']);
    Route::get('merchants/{merchant}/payment-audit/summary', [PaymentAuditController::class, 'summary'])
        ->middleware(['throttle:internal-service', 'internal:payments:audit:read', 'internal-https']);
    Route::get('merchants/{merchant}/api-keys', [MerchantManagementController::class, 'apiKeys'])
        ->middleware(['throttle:internal-service', 'internal:merchants:api-keys:read', 'internal-https']);
    Route::post('merchants/{merchant}/api-keys', [MerchantManagementController::class, 'issueApiKey'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:api-keys:issue', 'internal-https']);
    Route::get('merchants/{merchant}/api-key-issuances/{issuance}', [MerchantManagementController::class, 'issuanceStatus'])
        ->middleware(['throttle:internal-service', 'internal:merchants:api-keys:issue', 'internal-https']);
    Route::post('merchants/{merchant}/api-key-issuances/{issuance}/confirm', [MerchantManagementController::class, 'confirmApiKeyIssuance'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:api-keys:issue', 'internal-https']);
    Route::post('merchants/{merchant}/api-key-issuances/{issuance}/cancel', [MerchantManagementController::class, 'cancelApiKeyIssuance'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:api-keys:issue', 'internal-https']);
    Route::delete('merchants/{merchant}/api-keys/{apiKey}', [MerchantManagementController::class, 'revokeApiKey'])
        ->middleware(['throttle:internal-merchant-management', 'internal:merchants:api-keys:write', 'internal-https']);
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
