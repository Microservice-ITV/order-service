<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Services\CheckoutService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Logging\LogMessage;

class ConsumePaymentSucceeded extends BaseConsumer
{
    protected $signature = 'consume:payment-succeeded';
    protected $description = 'Consume payment succeeded events';

    protected function queue(): string
    {
        return config('rabbitmq.queues.payment');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PAYMENT_SUCCEEDED->value];
    }

    protected function process(array $payload): void
    {
        logger()->info(LogMessage::PAYMENT_SUCCEEDED_RECEIVED, [
            'payload' => $payload,
        ]);

        $data = $payload['data'] ?? $payload;
        $checkoutId = $data['checkout_id'] ?? null;

        if (!$checkoutId) {
            logger()->warning(LogMessage::PAYMENT_SUCCEEDED_MISSING_CHECKOUT_ID, [
                'payload' => $payload,
            ]);
            return;
        }

        if (!app(CheckoutService::class)->confirm($checkoutId)) {
            logger()->warning(LogMessage::PAYMENT_CONFIRM_IGNORED_INVALID_STATE, [
                'checkout_id' => $checkoutId,
            ]);
            return;
        }

        // session()->forget('cart');

        logger()->info(LogMessage::CHECKOUT_PAYMENT_CONFIRMED, [
            'checkout_id' => $checkoutId,
            'routing_key' => EventTopic::PAYMENT_SUCCEEDED->value,
        ]);
    }
}
