<?php

use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('orders')
    ->controller(OrderController::class)
    ->group(function () {
        Route::post('/', 'store');      // checkout
        Route::get('/', 'index');       // order history
        Route::get('/{order}', 'show'); // order detail
    });
