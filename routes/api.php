<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:cart-order'])
    ->group(function () {
        require __DIR__ . '/v1/cart.php';
        require __DIR__ . '/v1/order.php';
    });
