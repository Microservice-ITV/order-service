<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Logging\LogMessage;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Services\PaymentService;
use App\Services\RabbitMQ\BaseConsumer;
use Throwable;

/**
 * Deliveries confirm COD collection: when a shipment is marked delivered
 * (shipment.delivered), notify the payment service so the pending COD payment
 * is settled (payment.collected). Ignored for non-COD orders.
 */
class ConsumeShipmentDelivered extends BaseConsumer
{
    protected $signature = 'consume:shipment-delivered';
    protected $description = 'Consume shipment.delivered events and settle COD payments';

    protected function eventName(): string
    {
        return EventTopic::SHIPMENT_DELIVERED->value;
    }

    protected function queue(): string
    {
        return config('rabbitmq.queues.shipment_delivered');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::SHIPMENT_DELIVERED->value];
    }

    public function process(array $payload): void
    {
        $data = $payload['data'] ?? $payload;
        $orderId = $data['order_id'] ?? null;

        if (!$orderId) {
            logger()->warning(LogMessage::PAYMENT_COD_COLLECT_MISSING_ORDER_ID, [
                'payload' => $payload,
            ]);
            return;
        }

        logger()->info('SHIPMENT_DELIVERED_RECEIVED', [
            'order_id' => $orderId,
            'payload'  => $payload,
        ]);

        $order = Order::query()->where('order_id', (string) $orderId)->first();
        if (!$order) {
            logger()->info('SHIPMENT_DELIVERED_ORDER_NOT_FOUND', [
                'order_id' => $orderId,
            ]);
            return;
        }

        $checkout = CheckoutSession::query()->find($order->checkout_id);
        if (!$checkout || ($checkout->payment_method ?? 'card') !== 'cod') {
            logger()->info('SHIPMENT_DELIVERED_NON_COD_IGNORED', [
                'order_id'   => $order->order_id,
                'checkout_id' => $order->checkout_id,
            ]);
            return;
        }

        $paymentId = $order->payment_id;
        if (!$paymentId || $paymentId === '') {
            logger()->warning('SHIPMENT_DELIVERED_MISSING_PAYMENT_ID', [
                'order_id' => $order->order_id,
            ]);
            return;
        }

        try {
            $paymentService = app(PaymentService::class);
            $result = $paymentService->collectPayment($paymentId);

            logger()->info('PAYMENT_COD_COLLECTED_FROM_DELIVERY', [
                'order_id'   => $order->order_id,
                'payment_id' => $paymentId,
                'status'     => $result['status'] ?? null,
            ]);
        } catch (Throwable $e) {
            logger()->error('PAYMENT_COD_COLLECT_FAILED_FROM_DELIVERY', [
                'order_id'   => $order->order_id,
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}