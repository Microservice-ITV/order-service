<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventTopic;
use App\Http\Controllers\Controller;
use App\Services\OrderService;
use App\Services\RabbitMQ\Publisher;

class CheckoutController extends Controller
{
    public function checkout(OrderService $service)
    {
        logger('CHECKOUT API HIT');

        // Get items from request body first, then fallback to session
        $items = request()->input('items') ?? session('cart.items');

        if (!$items || empty($items)) {
            logger('CART EMPTY');
            return response()->json(['error' => 'Cart empty'], 400);
        }

        $order = $service->create();
        logger('ORDER CREATED', ['order_id' => $order->id]);

        Publisher::publish(EventTopic::ORDER_CHECKOUT_REQUESTED, [
            'order_id' => $order->id,
            'items' => $items
        ]);
        logger('ORDER_CREATED EVENT PUBLISHED');
        
        return response()->json(['order_id' => $order->id]);
    }
}
