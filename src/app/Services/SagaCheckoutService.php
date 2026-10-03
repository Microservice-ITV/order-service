<?php

namespace App\Services;

use App\Enums\EventTopic;
use App\Enums\SagaStep;
use App\Exceptions\SagaException;
use App\Models\SagaState;
use App\Repositories\SagaRepository;
use App\Services\EventPayloadBuilder;
use App\Services\RabbitMQ\Publisher;
use App\Services\SagaOrchestrator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Saga Checkout Service
 * 
 * Integrates saga orchestration with the existing checkout flow.
 * Handles the creation and management of order sagas.
 */
class SagaCheckoutService
{
    private CheckoutService $checkoutService;
    private SagaOrchestrator $sagaOrchestrator;
    private Publisher $eventPublisher;

    public function __construct(
        CheckoutService $checkoutService,
        SagaOrchestrator $sagaOrchestrator,
        Publisher $eventPublisher
    ) {
        $this->checkoutService = $checkoutService;
        $this->sagaOrchestrator = $sagaOrchestrator;
        $this->eventPublisher = $eventPublisher;
    }

    public function getCheckoutService(): CheckoutService
    {
        return $this->checkoutService;
    }

    public function getSagaRepository(): SagaRepository
    {
        return $this->sagaOrchestrator->getSagaRepository();
    }

    /**
     * Start checkout with saga orchestration
     */
    public function startSagaCheckout(
        array $items,
        string $userId,
        string $rawIdempotencyKey,
        ?array $deliveryAddress = null,
        string $paymentMethod = 'cod',
        ?array $customerMeta = null,
    ): array
    {
        try {
            // Create checkout session first
            $checkoutResult = $this->checkoutService->startCheckout(
                $items,
                $userId,
                $rawIdempotencyKey,
                $deliveryAddress,
                $paymentMethod,
                $customerMeta,
            );

            if ($checkoutResult['existing']) {
                $saga = $this->sagaOrchestrator->sagaRepository->findByOrderId($checkoutResult['checkout']->id);

                return [
                    'checkout' => $checkoutResult['checkout'],
                    'existing' => true,
                    'saga_id' => $saga ? $saga->saga_id : null,
                    'message' => 'Checkout session already exists'
                ];
            }

            $checkout = $checkoutResult['checkout'];

            // Start saga for order creation
            $sagaId = $this->sagaOrchestrator->startSaga(
                'ORDER_CREATION',
                [
                    'order_id' => $checkout->id,
                    'correlation_id' => $checkout->idempotency_key,
                    'checkout_id' => $checkout->id,
                    'user_id' => $userId,
                    'customer_type' => (string) ($customerMeta['type'] ?? 'USER'),
                    'guest_id' => $customerMeta['guest_id'] ?? null,
                    'guest_phone' => $customerMeta['guest_phone'] ?? null,
                    'items' => $items,
                    'delivery_address' => $deliveryAddress,
                    'created_at' => now()->toISOString(),
                ]
            );

            // Publish initial order event
            $this->publishOrderCheckoutEvent($checkout, $checkout->idempotency_key, $sagaId);

            return [
                'checkout' => $checkout,
                'existing' => false,
                'saga_id' => $sagaId,
                'message' => 'Checkout started with saga orchestration'
            ];

        } catch (SagaException $e) {
            Log::error('Saga checkout failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'items' => $items,
            ]);

            throw $e;
        }
    }

    /**
     * Complete checkout with saga
     */
    public function completeSagaCheckout(string $checkoutId, array $paymentData = null): array
    {
        try {
            $checkout = $this->checkoutService->validateCheckout($checkoutId);
            if (!$checkout) {
                throw new SagaException('Checkout session not found or expired');
            }

            // Update checkout with payment information if provided
            if ($paymentData) {
                $this->checkoutService->updatePayment($checkoutId, $paymentData);
            }

            // Saga should handle the rest of the process
            $saga = $this->sagaOrchestrator->sagaRepository->findByOrderId($checkoutId);
            if (!$saga) {
                throw new SagaException('No saga found for checkout');
            }

            return [
                'checkout' => $checkout,
                'saga_id' => $saga->saga_id,
                'status' => $saga->status,
                'message' => 'Checkout completed, saga processing'
            ];

        } catch (SagaException $e) {
            Log::error('Saga checkout completion failed', [
                'checkout_id' => $checkoutId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get saga status
     */
    public function getSagaStatus(string $sagaId): array
    {
        $saga = $this->sagaOrchestrator->getSagaRepository()->findById($sagaId);
        if (!$saga) {
            throw new SagaException('Saga not found');
        }

        return [
            'saga_id' => $saga->saga_id,
            'status' => $saga->status,
            'type' => $saga->type,
            'order_id' => $saga->order_id,
            'correlation_id' => $saga->correlation_id,
            'current_step' => $saga->current_step,
            'total_steps' => $saga->total_steps,
            'completed_steps' => $saga->completed_steps ?? [],
            'failed_steps' => $saga->failed_steps ?? [],
            'compensation_required' => $saga->compensation_required,
            'error_message' => $saga->error_message,
            'started_at' => $saga->started_at?->toISOString(),
            'completed_at' => $saga->completed_at?->toISOString(),
            'timeout_at' => $saga->timeout_at?->toISOString(),
        ];
    }

    /**
     * Publish order checkout event with saga context
     */
    private function publishOrderCheckoutEvent($checkout, string $correlationId, string $sagaId): void
    {
        $checkout->loadMissing('items');
        $eventItems = $checkout->items->map(static function ($item): array {
            return [
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id ?? null,
                'sku' => $item->product_sku,
                'slug' => $item->product_slug,
                'name' => $item->product_title,
                'description' => $item->product_description,
                'image_url' => $item->image_url,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->price,
                'subtotal' => isset($item->line_total) ? (float) $item->line_total : ((float) $item->price * (int) $item->qty),
                'currency' => $item->currency ?? 'USD',
                'shop_id' => $item->shop_id,
                'category_id' => $item->category_id,
                'available_stock' => isset($item->available_stock) ? (int) $item->available_stock : null,
            ];
        })->values()->all();

        $currency = 'USD';
        if ($eventItems !== []) {
            $currency = (string) ($eventItems[0]['currency'] ?? 'USD');
        }

        $subtotal = (float) array_reduce(
            $eventItems,
            static fn (float $carry, array $item): float => $carry + (float) ($item['subtotal'] ?? ((float) $item['unit_price'] * (int) $item['qty'])),
            0.0
        );

        $shippingTotal = round((float) ($checkout->shipping_total ?? 0), 2);
        $discountTotal = round((float) ($checkout->promotion_discount_total ?? 0), 2);
        $grandTotal = round((float) ($checkout->total ?? max(0, $subtotal + $shippingTotal - $discountTotal)), 2);

        $payload = EventPayloadBuilder::build(EventTopic::SAGA_ORDER_CREATED, $correlationId, [
            'saga_id' => $sagaId,
            'order_id' => $checkout->id,
            'checkout_id' => $checkout->id,
            'user_id' => (string) $checkout->user_id,
            'status' => 'CREATED',
            'items' => $eventItems,
            'subtotal' => round($subtotal, 2),
            'shipping_total' => $shippingTotal,
            'promotion_discount_total' => $discountTotal,
            'total' => $grandTotal,
            'currency' => $currency,
            'delivery_address' => $checkout->delivery_address,
            'placedAt' => optional($checkout->created_at)?->copy()->utc()->toIso8601String(),
            'updatedAt' => optional($checkout->updated_at)?->copy()->utc()->toIso8601String(),
            'expires_at' => $checkout->getExpireAtIso(),
        ]);

        try {
            $this->eventPublisher->publish(EventTopic::SAGA_ORDER_CREATED, $payload);

            Log::info('Saga order event published', [
                'checkout_id' => $checkout->id,
                'saga_id' => $sagaId,
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Saga order event publish failed', [
                'checkout_id' => $checkout->id,
                'saga_id' => $sagaId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get saga statistics
     */
    public function getSagaStatistics(): array
    {
        $totalSagas = \App\Models\SagaState::query()->count();
        $activeSagas = \App\Models\SagaState::query()->active()->count();
        $completedSagas = \App\Models\SagaState::query()->where('status', 'COMPLETED')->count();
        $failedSagas = \App\Models\SagaState::query()->where('status', 'FAILED')->count();
        $compensatingSagas = \App\Models\SagaState::query()->where('status', 'COMPENSATING')->count();

        return [
            'total_sagas' => $totalSagas,
            'active_sagas' => $activeSagas,
            'completed_sagas' => $completedSagas,
            'failed_sagas' => $failedSagas,
            'compensating_sagas' => $compensatingSagas,
            'success_rate' => $totalSagas > 0 ? round(($completedSagas / $totalSagas) * 100, 2) : 0,
        ];
    }
}
