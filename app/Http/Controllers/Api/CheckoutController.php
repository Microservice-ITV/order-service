<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventTopic;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderService;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CheckoutController extends Controller
{
    public function checkout(OrderService $service): JsonResponse
    {
        logger('CHECKOUT API HIT');

        $items = $this->getCheckoutItems();
        if ($items === []) {
            logger('CART EMPTY');
            return $this->errorResponse('Cart empty', 400);
        }

        $idempotencyHash = $this->makeIdempotencyHash($items);
        $existing = $this->findOrderByIdempotencyHash($idempotencyHash);
        if ($existing) {
            return $this->existingOrderResponse($existing);
        }

        $pricingData = $this->fetchPricingData($items);
        if ($pricingData['ok'] !== true) {
            return $this->errorResponse('Unable to fetch product prices', 502);
        }
        if ($pricingData['missing'] !== []) {
            return $this->errorResponse('Some products are unavailable', 400, ['missing' => $pricingData['missing']]);
        }

        try {
            $order = $this->createOrderAndItems($service, $items, $pricingData['priceByProduct'], $idempotencyHash);
        } catch (QueryException $e) {
            if ($this->isDuplicateKeyError($e)) {
                $existing = $this->findOrderByIdempotencyHash($idempotencyHash);
                if ($existing) {
                    return $this->existingOrderResponse($existing);
                }
            }

            throw $e;
        }

        logger('ORDER CREATED', ['order_id' => $order->id]);
        $this->publishOrderCheckoutEvent($order->id, $items);
        
        return $this->successResponse($order->id);
    }

    /**
     * @return array<int, array{product_id: string, qty: int}>
     */
    private function getCheckoutItems(): array
    {
        $items = request()->input('items') ?? session('cart.items');
        if (!\is_array($items) || $items === []) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $productId = (string) ($item['product_id'] ?? '');
            $qty = (int) ($item['qty'] ?? 0);

            if ($productId === '' || $qty <= 0) {
                continue;
            }

            $result[] = ['product_id' => $productId, 'qty' => $qty];
        }

        return $result;
    }

    /**
     * @param array<int, array{product_id: string, qty: int}> $items
     */
    private function makeIdempotencyHash(array $items): string
    {
        // Best-practice: client sends a unique key per checkout attempt.
        $raw = trim((string) request()->header('Idempotency-Key', ''));

        if ($raw === '') {
            // Fallback: stable per session+items (less ideal than an explicit client key).
            $copy = $items;
            usort($copy, static fn (array $a, array $b) => $a['product_id'] <=> $b['product_id']);
            $raw = session()->getId() . '|' . \json_encode($copy);
        }

        if (\strlen($raw) > 255) {
            // Keep hashing input bounded for performance/log-safety.
            $raw = \substr($raw, 0, 255);
        }

        // Store only a keyed hash (security) + fixed length (index/storage efficiency).
        return hash_hmac('sha256', $raw, (string) config('app.key'));
    }

    private function findOrderByIdempotencyHash(string $hash): ?Order
    {
        // Extra 'and' keeps some PHP language servers happy with signature inference.
        return Order::where('idempotency_key', '=', $hash, 'and')->first();
    }

    /**
     * @param array<int, array{product_id: string, qty: int}> $items
     * @return array{ok: bool, missing: array<int, mixed>, priceByProduct: array<string, array{unit_price: mixed}>}
     */
    private function fetchPricingData(array $items): array
    {
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

            return ['ok' => false, 'missing' => [], 'priceByProduct' => []];
        }

        /** @var array<string, mixed> $pricingData */
        $pricingData = $pricingResponse->json();
        $missing = $pricingData['missing'] ?? [];

        logger('PRODUCT PRICE API SUCCESS', ['pricingData' => $pricingData]);

        $priceByProduct = [];
        foreach (($pricingData['data'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $productId = (string) ($row['product_id'] ?? '');
            if ($productId === '') {
                continue;
            }
            $priceByProduct[$productId] = $row;
        }

        return [
            'ok' => true,
            'missing' => \is_array($missing) ? $missing : [],
            'priceByProduct' => $priceByProduct,
        ];
    }

    /**
     * @param array<int, array{product_id: string, qty: int}> $items
     * @param array<string, array{unit_price: mixed}> $priceByProduct
     */
    private function createOrderAndItems(OrderService $service, array $items, array $priceByProduct, string $idempotencyHash): Order
    {
        return DB::transaction(function () use ($service, $items, $priceByProduct, $idempotencyHash) {
            // If two requests race, the unique index will reject one insert.
            $order = $service->create(['idempotency_key' => $idempotencyHash]);

            foreach ($items as $item) {
                $productId = $item['product_id'];
                $priceItem = $priceByProduct[$productId] ?? null;

                if (!\is_array($priceItem) || !\array_key_exists('unit_price', $priceItem)) {
                    throw new \RuntimeException("Missing price for product: {$productId}");
                }

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'qty' => $item['qty'],
                    'price' => $priceItem['unit_price'],
                ]);
            }

            return $order;
        });
    }

    private function existingOrderResponse(Order $order): JsonResponse
    {
        return response()->json([
            'order_id' => $order->id,
            'message' => 'Order already received and we are processing your order',
        ]);
    }

    private function successResponse(string $orderId): JsonResponse
    {
        return response()->json([
            'order_id' => $orderId,
            'message' => 'Order received and we are processing your order',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function errorResponse(string $message, int $status = 400, array $data = []): JsonResponse
    {
        return response()->json([
            'error' => $message,
            ...$data,
        ], $status);
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        // MySQL duplicate key error code.
        $errorCode = (int) ($e->errorInfo[1] ?? 0);
        return $errorCode === 1062;
    }

    /**
     * @param array<int, array{product_id: string, qty: int}> $items
     */
    private function publishOrderCheckoutEvent(string $orderId, array $items): void
    {
        Publisher::publish(EventTopic::ORDER_CHECKOUT_REQUESTED, [
            'event' => EventTopic::ORDER_CHECKOUT_REQUESTED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
            'data' => [
                'order_id' => $orderId,
                'items' => $items,
            ],
        ]);
        logger('ORDER_CREATED EVENT PUBLISHED');
    }
}
