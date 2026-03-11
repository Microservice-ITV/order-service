<?php

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\CheckoutSessionController;
use App\Http\Controllers\Api\CreateShipmentController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Order Management Routes (auth handled by auth-service / API gateway if needed)
Route::controller(CheckoutController::class)->prefix('orders')->group(function () {
    Route::post('/checkout', 'checkout')->name('orders.checkout');
    Route::put('/checkout/{checkout_id}/address', 'updateAddress')->name('orders.checkout.address.update');
    Route::get('/checkout-sessions/{checkout_id}', [CheckoutSessionController::class, 'show'])->name('checkout-sessions.show');
    Route::post('/checkout-sessions/{checkout_id}/cancel', [CheckoutSessionController::class, 'cancel'])->name('checkout-sessions.cancel');
    Route::post('/{orderId}/create-shipment', CreateShipmentController::class)->name('orders.create-shipment');
});

// Backward-compatibility alias for clients using singular "order" prefix.
Route::controller(CheckoutController::class)->prefix('order')->group(function () {
    Route::put('/checkout/{checkout_id}/address', 'updateAddress')->name('order.checkout.address.update');
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
