<?php

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\CheckoutSessionController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Order Management Routes (auth handled by auth-service / API gateway if needed)
Route::controller(CheckoutController::class)->prefix('orders')->group(function () {
    Route::post('/checkout', 'checkout')->name('orders.checkout');
    Route::get('/checkout-sessions/{checkout_id}', [CheckoutSessionController::class, 'show'])->name('checkout-sessions.show');
    Route::post('/checkout-sessions/{checkout_id}/cancel', [CheckoutSessionController::class, 'cancel'])->name('checkout-sessions.cancel');
});

// Webhook Routes (verify HMAC signature when WEBHOOK_SECRET is set)
Route::controller(WebhookController::class)->prefix('webhooks')->middleware('webhook.signature')->group(function () {
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
