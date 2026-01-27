<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Models\CheckoutItem;
use App\Services\CheckoutService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\DB;

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
        logger('PRODUCT_VALIDATED EVENT RECEIVED', ['payload' => $payload]);

        $data = $payload['data'] ?? $payload;
        $checkoutId = $data['checkout_id'] ?? null;

        if (!$checkoutId) {
            logger('PRODUCT_VALIDATED payload missing checkout_id', ['payload' => $payload]);
            return;
        }

        DB::transaction(function () use ($data, $checkoutId) {
            
            $total = $data['total'] ?? $this->calculateOrderTotal($checkoutId);
            $reservationId = $data['reservation_id'] ?? null;
            $correlationId = $data['correlation_id'] ?? null;

            if (!is_string($reservationId) || $reservationId === '') {
                throw new \RuntimeException('Invalid reservation_id');
            }

            app(CheckoutService::class)->markStockConfirmed($checkoutId, $total, $reservationId);

            Publisher::publish(EventTopic::PAYMENT_REQUESTED, [
                'event' => EventTopic::PAYMENT_REQUESTED->value,
                'version' => 'v1',
                'timestamp' => now()->utc()->toIso8601String(),
                'producer' => 'order-service',
                "correlation_id" => $correlationId,
                'data' => [
                    'checkout_id' => $checkoutId,
                    'amount' => $total,
                ],
            ]);

            logger('PAYMENT_REQUESTED EVENT PUBLISHED', [
                'checkout_id' => $checkoutId,
                'routing_key' => EventTopic::PAYMENT_REQUESTED->value,
            ]);

            app(CheckoutService::class)->markPendingPayment($checkoutId);

            logger('PENDING_PAYMENT STATUS SET', [
                'checkout_id' => $checkoutId,
            ]);
        });
    }

    private function calculateOrderTotal(string $checkoutId): float
    {
        logger('CALCULATING CHECKOUT TOTAL', ['checkout_id' => $checkoutId]);
        return (float) CheckoutItem::query()
            ->where('checkout_id', '=', $checkoutId)
            ->get()
            ->sum(function (CheckoutItem $item) {
                return $item->qty * (float) ($item->price ?? 0);
            });
    }
}
