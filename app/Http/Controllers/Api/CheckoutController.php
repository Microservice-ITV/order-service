<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CheckoutSession;
use App\Models\CheckoutItem;
use App\Services\CheckoutService;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function checkout(CheckoutService $service): JsonResponse
    {
        logger('CHECKOUT API HIT');

        $items = $this->getCheckoutItems();
        if ($items === []) {
            logger('CART EMPTY');
            return $this->errorResponse('Cart empty', 400);
        }

        $rawIdempotencyKey = $this->requireIdempotencyKey();
        $idempotencyHash   = $this->hashIdempotencyKey($rawIdempotencyKey);

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
            $checkout = $this->createCheckoutSessionAndItems($service, $items, $pricingData['priceByProduct'], $idempotencyHash);
        } catch (QueryException $e) {
            if ($this->isDuplicateKeyError($e)) {
                $existing = $this->findOrderByIdempotencyHash($idempotencyHash);
                if ($existing) {
                    return $this->existingOrderResponse($existing);
                }
            }

            throw $e;
        }

        logger('CHECKOUT SESSION CREATED', ['checkout_id' => $checkout->id]);
        $this->publishOrderCheckoutEvent($checkout->id, $items, $idempotencyHash);
        
        return $this->successResponse($checkout->id);
    }

    private function getCheckoutItems(): array
    {
        $items = request()->input('items') ?? session('cart.items');
        if (!is_array($items) || $items === []) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {

            if (!is_array($item)) {
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

    private function requireIdempotencyKey(): string
    {
        $key = trim((string) request()->header('Idempotency-Key'));

        if ($key === '') {
            abort(400, 'Idempotency-Key header is required');
        }

        return $key;
    }
    private function hashIdempotencyKey(string $raw): string
    {
        if (\strlen($raw) > 255) {
            $raw = substr($raw, 0, 255);
        }

        return hash_hmac('sha256', $raw, (string) config('app.key'));
    }

    private function findOrderByIdempotencyHash(string $hash): ?CheckoutSession
    {
        // Extra 'and' keeps some PHP language servers happy with signature inference.
        // return CheckoutSession::where('idempotency_key', '=', $hash, 'and')->first();
        return CheckoutSession::where('idempotency_key', $hash)->first();
    }

    private function fetchPricingData(array $items): array
    {
        $pricingResponse = Http::timeout(3)->retry(2, 100)
        ->withHeaders([
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

        $pricingData = $pricingResponse->json();
        $missing = $pricingData['missing'] ?? [];

        logger('PRODUCT PRICE API SUCCESS', ['pricingData' => $pricingData]);

        $priceByProduct = [];
        foreach (($pricingData['data'] ?? []) as $row) {
            if (!is_array($row) || empty($row['product_id'])) {
                continue;
            }
            $priceByProduct[(string) $row['product_id']] = $row;
            // if (!is_array($row)) {
            //     continue;
            // }
            // $productId = (string) ($row['product_id'] ?? '');
            // if ($productId === '') {
            //     continue;
            // }
            // $priceByProduct[$productId] = $row;
        }

        return [
            'ok' => true,
            'missing' => is_array($missing) ? $missing : [],
            'priceByProduct' => $priceByProduct,
        ];
    }

    private function createCheckoutSessionAndItems(CheckoutService $service, array $items, array $priceByProduct, string $idempotencyHash): CheckoutSession
    {
        return DB::transaction(function () use ($service, $items, $priceByProduct, $idempotencyHash) {
            // If two requests race, the unique index will reject one insert.
            $checkoutSession = $service->create([
                'idempotency_key' => $idempotencyHash,
                'status_id'       => OrderStatus::PENDING_STOCK->value,
                'reservation_id' => (string) Str::uuid(),
                'payment_id' => (string) Str::uuid(),
                'correlation_id' => $idempotencyHash,
            ]);

            /** ✅ BULK INSERT ITEMS */
            $rows = [];
            $now  = now();

            foreach ($items as $item) {
                $productId = $item['product_id'];

                if (!isset($priceByProduct[$productId]['unit_price'])) {
                    throw new \RuntimeException("Missing price for product {$productId}");
                }

                $rows[] = [
                    'checkout_id' => $checkoutSession->id,
                    'product_id'  => $productId,
                    'qty'         => $item['qty'],
                    'price'       => $priceByProduct[$productId]['unit_price'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
            
            CheckoutItem::insert($rows);

            return $checkoutSession;
        });
    }

    private function existingOrderResponse(CheckoutSession $checkout): JsonResponse
    {
        return response()->json([
            'checkout_id' => $checkout->id,
            'status'      => $checkout->status_id,
            'message'     => match ($checkout->status_id) {
                OrderStatus::PAID->value => 'Order already paid',
                OrderStatus::CANCELLED->value => 'Order was cancelled',
                default => 'Order already received and is processing',
            }
        ]);
    }


    private function successResponse(string $checkoutId): JsonResponse
    {
        return response()->json([
            'checkout_id' => $checkoutId,
            'message' => 'Order received and we are processing your order',
        ]);
    }

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

    private function publishOrderCheckoutEvent(string $checkoutId, array $items, string $correlationId): void
    {
        Publisher::publish(EventTopic::ORDER_CHECKOUT_REQUESTED, [
            'event' => EventTopic::ORDER_CHECKOUT_REQUESTED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
            "correlation_id" => $correlationId,
            'data' => [
                'checkout_id' => $checkoutId,
                'items' => $items,
            ],
        ]);
        logger()->info('ORDER_CHECKOUT_REQUESTED EVENT PUBLISHED', [
            'checkout_id' => $checkoutId
        ]);
    }
}
