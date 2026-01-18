<?php

use App\Http\Controllers\Api\CartController;
use Illuminate\Support\Facades\Route;

Route::prefix('cart')
    ->controller(CartController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/items', 'store');
        Route::patch('/items/{item}', 'update');
        Route::delete('/items/{item}', 'destroy');
        Route::delete('/', 'clear');
    });
