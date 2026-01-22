<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventTopic;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\OrderService;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

        /** @var Response $pricingResponse */
        $pricingResponse = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-SERVICE-KEY' => config('services.product.api_key'),
        ])->post(rtrim(config('services.product.url'), '/') . '/api/v1/products/prices', [
            'items' => $items,
        ]);

        if (!$pricingResponse->ok() || !($pricingResponse->json('success') ?? false)) {
            logger('PRODUCT PRICE API FAILED', [
                'status' => $pricingResponse->status(),
                'body' => $pricingResponse->json(),
            ]);

            return response()->json(['error' => 'Unable to fetch product prices'], 502);
        }

        $pricingData = $pricingResponse->json();
        $missingItems = $pricingData['missing'] ?? [];

        if (!empty($missingItems)) {
            return response()->json([
                'error' => 'Some products are unavailable',
                'missing' => $missingItems,
            ], 400);
        }
        logger('PRODUCT PRICE API SUCCESS', ['pricingData' => $pricingData]);
        
        $priceByProduct = collect($pricingData['data'] ?? [])
            ->keyBy('product_id');

        $order = DB::transaction(function () use ($service, $items, $priceByProduct) {
            $order = $service->create();

            foreach ($items as $item) {
                $priceItem = $priceByProduct->get($item['product_id']);

                if (!$priceItem) {
                    throw new \RuntimeException('Missing price for product: ' . $item['product_id']);
                }

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'price' => $priceItem['unit_price'],
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
