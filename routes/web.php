<?php

use App\Http\Controllers\Api\V1\DanaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/payment/finish', 'payment.finish')->name('payment.finish');

Route::post('v1.0/debit/notify', DanaWebhookController::class)
    ->middleware('throttle:60,1');

Route::post('payment-gateway/v1.0/debit/notify', DanaWebhookController::class)
    ->middleware('throttle:60,1');

