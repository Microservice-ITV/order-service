<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventTopic;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\OrderService;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\DB;

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

        $order = DB::transaction(function () use ($service, $items) {
            $order = $service->create();

            foreach ($items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'price' => $item['price'] ?? null,
                ]);
            }

            return $order;
        });
        logger('ORDER CREATED', ['order_id' => $order->id]);

        Publisher::publish(EventTopic::ORDER_CHECKOUT_REQUESTED, [
            'event' => EventTopic::ORDER_CHECKOUT_REQUESTED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
            'data' => [
                'order_id' => $order->id,
                'items' => $items,
            ],
        ]);
        logger('ORDER_CREATED EVENT PUBLISHED');
        
        return response()->json([
            'order_id' => $order->id,
            'message' => 'Order received and we are processing your order',
        ]);
    }
}
