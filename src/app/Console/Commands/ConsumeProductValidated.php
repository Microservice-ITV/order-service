<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Models\CheckoutItem;
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

        $checkoutService->markStockConfirmed($checkoutId, $total, $reservationId);

        logger()->info(LogMessage::CHECKOUT_STOCK_CONFIRMED, [
            'checkout_id'    => $checkoutId,
            'reservation_id' => $reservationId,
        ]);

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
