<?php

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\CheckoutSessionController;
use App\Http\Controllers\Api\CreateShipmentController;
use App\Http\Controllers\Api\Internal\InvoiceSnapshotController;
use App\Http\Controllers\Api\CollectOrderPaymentController;
use App\Http\Controllers\Api\OrderPaymentDetailController;
use App\Http\Controllers\Api\OrderPaymentRecordsController;
use App\Http\Controllers\Api\SagaCheckoutController;
use App\Http\Controllers\Api\Internal\WalletSplitController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Wallet Service (service-to-service): per-shop payout split for ledger distribution
Route::middleware('wallet.internal')->prefix('internal/orders')->group(function () {
    Route::get('{orderId}/wallet-split', [WalletSplitController::class, 'show'])
        ->name('internal.orders.wallet-split');
    Route::get('{orderId}/invoice-snapshot', [InvoiceSnapshotController::class, 'show'])
        ->name('internal.orders.invoice-snapshot');
});

// Order Management Routes (auth handled by auth-service / API gateway if needed)
Route::controller(CheckoutController::class)->prefix('orders')->group(function () {
    Route::get('/payments', OrderPaymentRecordsController::class)->name('orders.payments');
    Route::get('/payments/{paymentId}', OrderPaymentDetailController::class)->name('orders.payment-detail');
    Route::post('/checkout', 'checkout')->name('orders.checkout');
    Route::put('/checkout/{checkout_id}/address', 'updateAddress')->name('orders.checkout.address.update');
    Route::get('/checkout-sessions/{checkout_id}', [CheckoutSessionController::class, 'show'])->name('checkout-sessions.show');
    Route::post('/checkout-sessions/{checkout_id}/cancel', [CheckoutSessionController::class, 'cancel'])->name('checkout-sessions.cancel');
    Route::post('/{orderId}/create-shipment', CreateShipmentController::class)->name('orders.create-shipment');
    Route::post('/{orderId}/collect-payment', CollectOrderPaymentController::class)->name('orders.collect-payment');
});

// Saga-based Order Management Routes (auth handled by auth-service / API gateway if needed)
Route::controller(SagaCheckoutController::class)->prefix('saga')->group(function () {
    Route::post('/orders/checkout', 'startCheckout')->name('saga.orders.checkout');
    Route::post('/orders/{checkoutId}/complete', 'completeCheckout')->name('saga.orders.complete');
    Route::get('/sagas/{sagaId}', 'getSagaStatus')->name('sagas.status');
    Route::get('/sagas/statistics', 'getSagaStatistics')->name('sagas.statistics');
    Route::get('/orders/{checkoutId}/status', 'getCheckoutStatus')->name('saga.orders.status');
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
