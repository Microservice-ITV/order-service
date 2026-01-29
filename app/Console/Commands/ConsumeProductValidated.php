<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Models\CheckoutItem;
use App\Services\CheckoutService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Services\RabbitMQ\Publisher;
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
            
            $total = $data['total'] ?? $this->calculateOrderTotal($checkoutId);
            $reservationId = $data['reservation_id'] ?? null;
            $correlationId = $data['correlation_id'] ?? null;

            // if (!is_string($reservationId) || $reservationId === '') {
            //     throw new \RuntimeException('Invalid reservation_id');
            // }

            if (!is_string($reservationId) || $reservationId === '') {
                logger()->error(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                    'checkout_id' => $checkoutId,
                    'reason'      => 'INVALID_RESERVATION_ID',
                ]);

                throw new \RuntimeException('Invalid reservation_id');
            }

            app(CheckoutService::class)->markStockConfirmed($checkoutId, $total, $reservationId);

            logger()->info(LogMessage::CHECKOUT_STOCK_CONFIRMED, [
                'checkout_id'    => $checkoutId,
                'reservation_id' => $reservationId,
            ]);

            Publisher::publish(EventTopic::PAYMENT_REQUESTED, [
                'event' => EventTopic::PAYMENT_REQUESTED->value,
                'version' => 'v1',
                'timestamp' => now()->utc()->toIso8601String(),
                'producer' => 'order-service',
                "correlation_id" => $correlationId,
                'data' => [
                    'checkout_id' => $checkoutId,
                    'reservation_id' => $reservationId,
                    'amount' => $total,
                ],
            ]);

            logger()->info(LogMessage::PAYMENT_REQUESTED_PUBLISHED, [
                'checkout_id' => $checkoutId,
                'amount'      => $total,
            ]);

            app(CheckoutService::class)->markPendingPayment($checkoutId);

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
