<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Services\CheckoutPaymentPreparationService;
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

        $preparationService = app(CheckoutPaymentPreparationService::class);

        $total = (float) ($data['total'] ?? $preparationService->calculateCheckoutItemsTotal($checkoutId));
        $reservationId = (string) ($data['reservation_id'] ?? '');
        $correlationId = (string) ($data['correlation_id'] ?? $payload['correlation_id'] ?? '');

        $preparationService->confirmStockAndPreparePayment(
            checkoutId: $checkoutId,
            reservationId: $reservationId,
            total: $total,
            correlationId: $correlationId
        );
    }
}
