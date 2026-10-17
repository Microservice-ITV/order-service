<?php

namespace App\Services;

use App\Enums\EventTopic;
use App\Exceptions\SagaException;
use App\Repositories\SagaRepository;
use App\Services\EventPayloadBuilder;
use App\Services\RabbitMQ\Publisher;
use App\Services\SagaOrchestrator;
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
        Publisher $eventPublisher,
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
                deferOrderEvent: true,
            );

            if ($checkoutResult['existing']) {
                $saga = $this->sagaOrchestrator->getSagaRepository()->findByOrderId(
                    $checkoutResult['checkout']->id,
                );

                return [
                    'checkout' => $checkoutResult['checkout'],
                    'existing' => true,
                    'saga_id' => $saga ? $saga->saga_id : null,
                    'message' => 'Checkout session already exists'
                ];
            }

            $checkout = $checkoutResult['checkout'];

            // Start saga for order creation
            $sagaData = [
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
            ];

            try {
                $sagaId = $this->sagaOrchestrator->startSaga(
                    'ORDER_CREATION',
                    $sagaData,
                );
            } catch (\Throwable $e) {
                $this->checkoutService->reject($checkout->id, 'SAGA_INITIALIZATION_FAILED');
                Log::error('Saga initialization failed; checkout rejected before publishing stock request', [
                    'checkout_id' => $checkout->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            // Keep the saga event for external observers, but publish it only
            // after the saga row exists and use the standard envelope shape.
            try {
                $this->eventPublisher->publish(
                    EventTopic::SAGA_ORDER_CREATED,
                    EventPayloadBuilder::build(
                        EventTopic::SAGA_ORDER_CREATED,
                        (string) $checkout->idempotency_key,
                        [
                            ...$sagaData,
                            'saga_id' => $sagaId,
                            'type' => 'ORDER_CREATION',
                        ],
                    ),
                );
            } catch (\Throwable $e) {
                // The event publisher persists failed outbox rows for scheduled
                // retry; it must not prevent the existing checkout flow.
                Log::warning('Saga creation event publication deferred to outbox retry', [
                    'checkout_id' => $checkout->id,
                    'saga_id' => $sagaId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Persist the saga before publishing the existing checkout event. The
            // stock-reserved consumer can run immediately and must be able to
            // find its saga before recording the inventory step.
            $this->checkoutService->publishOrderCheckoutEvent(
                $checkout,
                (string) $checkout->idempotency_key,
            );

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
    public function completeSagaCheckout(string $checkoutId, ?array $paymentData = null): array
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
