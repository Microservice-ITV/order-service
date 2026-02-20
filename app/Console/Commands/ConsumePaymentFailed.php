<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Services\CheckoutService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Logging\LogMessage;

class ConsumePaymentFailed extends BaseConsumer
{
    protected $signature = 'consume:payment-failed';
    protected $description = 'Consume payment failed events';

    protected function queue(): string
    {
        return config('rabbitmq.queues.payment_failed');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PAYMENT_FAILED->value];
    }

    protected function process(array $payload): void
    {
        logger()->info(LogMessage::PAYMENT_FAILED_RECEIVED, [
            'payload' => $payload,
        ]);

        $data = $payload['data'] ?? $payload;
         $checkoutId = $data['checkout_id'] ?? null;

        if (!$checkoutId) {
            logger()->warning(LogMessage::PAYMENT_FAILED_MISSING_CHECKOUT_ID, [
                'payload' => $payload,
            ]);
            return;
        }

        $reason = $data['reason'] ?? null;
        // app(CheckoutService::class)->fail($checkoutId, $reason);
        if (!app(CheckoutService::class)->fail($checkoutId, $reason)) {
            logger()->warning(LogMessage::PAYMENT_FAIL_IGNORED_INVALID_STATE, [
                'checkout_id' => $checkoutId,
            ]);
            return;
        }

        logger()->info(LogMessage::CHECKOUT_PAYMENT_FAILED, [
            'checkout_id' => $checkoutId,
            'routing_key' => EventTopic::PAYMENT_FAILED->value,
            'reason'      => $reason,
        ]);
    }
}
