<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Models\CheckoutItem;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\RabbitMQ\BaseConsumer;
use Illuminate\Support\Facades\DB;
use App\Logging\LogMessage;

class ConsumeProductValidated extends BaseConsumer
{
    protected $signature = 'consume:product-validated';
    protected $description = 'Consume product validated events';

    protected function queue(): string
    {
        return config('rabbitmq.queues.product_reserved');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PRODUCT_VALIDATED->value];
    }

    protected function process(array $payload): void
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

        DB::transaction(function () use ($data, $checkoutId) {
            $checkoutService = app(CheckoutService::class);
            $paymentService  = app(PaymentService::class);
            
            // Validate checkout exists and is not expired
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

            // Create payment intent via REST API
            $paymentIntent = $paymentService->createPaymentIntent(
                checkoutId: $checkoutId,
                reservationId: $reservationId,
                amount: $total,
                correlationId: $correlationId,
                expireAtIso: $checkout->getExpireAtIso()
            );

            $paymentId    = (string) $paymentIntent['payment_id'];
            $clientSecret = (string) $paymentIntent['client_secret'];

            $checkoutService->attachPaymentIntent($checkoutId, $paymentId, $clientSecret);
            $checkoutService->markPendingPayment($checkoutId);

            logger()->info(LogMessage::PAYMENT_REQUESTED_PUBLISHED, [
                'checkout_id' => $checkoutId,
                'amount'      => $total,
            ]);

            logger()->info(LogMessage::CHECKOUT_PENDING_PAYMENT, [
                'checkout_id' => $checkoutId,
            ]);
        });
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
