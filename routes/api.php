<?php

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Order Management Routes
Route::controller(CheckoutController::class)->prefix('orders')->group(function () {
    Route::post('/checkout', 'checkout')->name('orders.checkout');
});

// Webhook Routes (Event simulation from other microservices)
Route::controller(WebhookController::class)->prefix('webhooks')->group(function () {
    // Product Service Events
    Route::prefix('product')->group(function () {
        Route::post('/validated', 'productValidated')->name('webhook.product.validated');
        Route::post('/rejected', 'productRejected')->name('webhook.product.rejected');
    });

    // Payment Service Events
    Route::prefix('payment')->group(function () {
        Route::post('/succeeded', 'paymentSucceeded')->name('webhook.payment.succeeded');
        Route::post('/failed', 'paymentFailed')->name('webhook.payment.failed');
    });
});
