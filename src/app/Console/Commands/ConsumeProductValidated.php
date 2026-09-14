<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Models\CheckoutItem;
use App\Repositories\SagaRepository;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Logging\LogMessage;

class ConsumeProductValidated extends BaseConsumer
{
    protected $signature = 'consume:stock-reserved|consume:product-validated';
    protected $description = 'Consume product.stock.reserved (stock reserved) events from product service';

    protected function eventName(): string
    {
        return EventTopic::PRODUCT_VALIDATED->value;
    }

    protected function queue(): string
    {
        return config('rabbitmq.queues.stock_reserved');
    }

    protected function routingKeys(): array
    {
        return [
            EventTopic::PRODUCT_VALIDATED->value,
        ];
    }

    public function process(array $payload): void
    {
        logger()->info(LogMessage::PRODUCT_VALIDATED_RECEIVED, [
            'payload' => $payload,
        ]);

        $data = $payload['data'] ?? $payload;
        $checkoutId = $data['checkout_id'] ?? null;

        if (!$checkoutId) {
            logger()->warning(LogMessage::PRODUCT_VALIDATED_MISSING_CHECKOUT_ID, [
                'payload' => $payload,
            ]);
            return;
        }

        $checkoutService = app(CheckoutService::class);
        $paymentService  = app(PaymentService::class);
        $promotionQuoteService = app(\App\Services\PromotionQuoteService::class);

        $checkout = $checkoutService->validateCheckout($checkoutId);
        if (!$checkout) {
            logger()->warning(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                'checkout_id' => $checkoutId,
                'reason'      => 'CHECKOUT_NOT_FOUND_OR_EXPIRED',
            ]);
            return;
        }

        $total = (float) ($data['total'] ?? $this->calculateOrderTotal($checkoutId));
        $reservationId = (string) ($data['reservation_id'] ?? '');
        $correlationId = (string) ($data['correlation_id'] ?? '');
        if ($correlationId === '') {
            $correlationId = (string) ($checkout->correlation_id ?? '');
        }
        if ($correlationId === '') {
            logger()->error(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                'checkout_id' => $checkoutId,
                'reason'      => 'MISSING_CORRELATION_ID',
            ]);
            throw new \RuntimeException('Missing correlation_id');
        }

        if ($reservationId === '') {
            logger()->error(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                'checkout_id' => $checkoutId,
                'reason'      => 'INVALID_RESERVATION_ID',
            ]);
            throw new \RuntimeException('Invalid reservation_id');
        }

        try {
            $subtotal = (float) ($data['subtotal'] ?? $total);
            $snapshot = is_array($checkout->promotion_snapshot) ? $checkout->promotion_snapshot : [];
            $promoCode = isset($snapshot['promo_code']) && is_string($snapshot['promo_code'])
                ? $snapshot['promo_code']
                : null;
            $promotionQuote = $promotionQuoteService->quote(
                checkoutId: $checkoutId,
                userId: (string) $checkout->user_id,
                subtotal: $subtotal,
                shippingTotal: (float) ($checkout->shipping_total ?? 0),
                promoCode: $promoCode,
            );
            if ($promoCode !== null) {
                $promoStatus = $promotionQuote['promo_code_status'] ?? null;
                if ($promoStatus !== null && $promoStatus !== 'VALID') {
                    logger()->warning('PROMO_CODE_UNAVAILABLE_AT_CONFIRM_CHECKOUT_REJECTED', [
                        'checkout_id' => $checkoutId,
                        'promo_code' => $promoCode,
                        'promo_code_status' => $promoStatus,
                        'promo_code_message' => $promotionQuote['promo_code_message'] ?? null,
                    ]);
                    $checkoutService->reject($checkoutId, 'PROMO_CODE_UNAVAILABLE_AT_CONFIRM');
                    return;
                }
            }
            if (!$checkoutService->applyPromotionQuote($checkoutId, $promotionQuote)) {
                throw new \RuntimeException('Failed to persist promotion quote');
            }
            $total = $promotionQuote['final_total'];
        } catch (\Throwable $e) {
            logger()->error('PROMOTION_QUOTE_FAILED_CHECKOUT_REJECTED', [
                'checkout_id' => $checkoutId,
                'error' => $e->getMessage(),
            ]);
            $checkoutService->reject($checkoutId, 'PROMOTION_QUOTE_FAILED');
            return;
        }

        $stockConfirmed = $checkoutService->markStockConfirmed($checkoutId, $total, $reservationId);

        if (!$stockConfirmed && $checkout->status_id === \App\Enums\OrderStatus::PENDING_STOCK) {
            // Transition failed while session is still PENDING_STOCK: without
            // requeueing, the event would be acked and the checkout stuck in
            // PENDING_STOCK ("Confirming stock availability...") indefinitely.
            logger()->error('CHECKOUT_STOCK_CONFIRM_TRANSITION_FAILED_REQUEUE', [
                'checkout_id'    => $checkoutId,
                'reservation_id' => $reservationId,
                'status_id'      => $checkout->status_id->name,
            ]);
            throw new \RuntimeException("Failed to transition checkout {$checkoutId} out of PENDING_STOCK");
        }

        logger()->info(LogMessage::CHECKOUT_STOCK_CONFIRMED, [
            'checkout_id'    => $checkoutId,
            'reservation_id' => $reservationId,
        ]);

        // Advance the saga tracker: inventory reservation succeeded.
        $sagaRepository = app(SagaRepository::class);
        $saga = $sagaRepository->findByOrderId($checkoutId);
        if ($saga && !in_array('INVENTORY_RESERVATION', $saga->completed_steps ?? [], true)) {
            $sagaRepository->completeStep($saga->saga_id, 'INVENTORY_RESERVATION');

            logger()->info('SAGA_STEP_COMPLETED_FROM_STOCK_RESERVED', [
                'saga_id'  => $saga->saga_id,
                'step'     => 'INVENTORY_RESERVATION',
                'checkout_id' => $checkoutId,
            ]);
        }

        $expireAtIso = $checkout->getExpireAtIso();
        if ($expireAtIso === null) {
            logger()->error(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                'checkout_id' => $checkoutId,
                'reason'      => 'MISSING_EXPIRE_AT',
            ]);
            throw new \RuntimeException('Missing expire_at');
        }

        try {
            $paymentIntent = $paymentService->createPaymentIntent(
                checkoutId: $checkoutId,
                reservationId: $reservationId,
                amount: $total,
                correlationId: $correlationId,
                expireAtIso: $expireAtIso
            );
        } catch (\Throwable $e) {
            logger()->error('PAYMENT_INTENT_CREATION_FAILED_STOCK_STILL_CONFIRMED', [
                'checkout_id' => $checkoutId,
                'error'       => $e->getMessage(),
            ]);

            $checkoutService->markPaymentPreparationFailed(
                $checkoutId,
                'PAYMENT_INTENT_CREATION_FAILED'
            );

            return;
        }

        $paymentId    = (string) $paymentIntent['payment_id'];
        $clientSecret = (string) $paymentIntent['client_secret'];

        logger()->info("PAYMENT_INTENT_API_RESPONSE", [
            'payment_id' => $paymentId,
            'client_secret' => $clientSecret,
        ]);

        $checkoutService->updatePaymentIntent($checkoutId, $paymentId, $clientSecret);
        $checkoutService->markPendingPayment($checkoutId);

        logger()->info(LogMessage::CHECKOUT_PENDING_PAYMENT, [
            'checkout_id' => $checkoutId,
        ]);
    }

    private function calculateOrderTotal(string $checkoutId): float
    {
        logger()->info(LogMessage::CHECKOUT_TOTAL_CALCULATION_STARTED, [
            'checkout_id' => $checkoutId,
        ]);
        
        return (float) CheckoutItem::query()
            ->where('checkout_id', $checkoutId)
            ->get()
            ->sum(function (CheckoutItem $item) {
                return $item->qty * (float) ($item->price ?? 0);
            });
    }
}
