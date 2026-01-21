<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/**
 * Protected API Routes
 * Require JWT authentication
 */
Route::middleware(['auth:api', 'sanitize.input', 'rate.limit'])->group(function () {
    
    // Order Management Routes
    Route::prefix('orders')->controller(OrderController::class)->group(function () {
        Route::get('/', 'index')->name('orders.index');           // List user's orders
        Route::get('/{id}', 'show')->name('orders.show');         // Get order details
        Route::get('/statistics', 'statistics')->name('orders.statistics'); // Get statistics
    });

    // Checkout Route
    Route::post('/checkout', [CheckoutController::class, 'store'])
        ->name('checkout.store');
});

/**
 * Webhook Routes (from other microservices)
 * These are protected by API key or internal service verification
 * In production, add additional security (API signatures, IP whitelist, etc.)
 */
Route::prefix('webhooks')->controller(WebhookController::class)->group(function () {
    // Product Service Events
    Route::prefix('product')->group(function () {
        Route::post('/validated', 'productValidated')
            ->name('webhook.product.validated')
            ->withoutMiddleware(['auth:api']); // These are from other services
        Route::post('/rejected', 'productRejected')
            ->name('webhook.product.rejected')
            ->withoutMiddleware(['auth:api']);
    });

    // Payment Service Events
    Route::prefix('payment')->group(function () {
        Route::post('/succeeded', 'paymentSucceeded')
            ->name('webhook.payment.succeeded')
            ->withoutMiddleware(['auth:api']);
        Route::post('/failed', 'paymentFailed')
            ->name('webhook.payment.failed')
            ->withoutMiddleware(['auth:api']);
    });
});

/**
 * Health Check Route
 */
Route::get('/health', function () {
    return response()->json([
        'status'  => 'ok',
        'service' => 'order-service',
        'timestamp' => now()->toIso8601String(),
    ]);
})->withoutMiddleware(['auth:api', 'sanitize.input', 'rate.limit'])->name('health');

