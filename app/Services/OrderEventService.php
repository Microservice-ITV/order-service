<?php

namespace App\Services;

use App\Models\Order;
use App\Enums\EventTopic;
use App\Exceptions\EventPublishingException;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\Log;
use Exception;

class OrderEventService
{
    public function __construct(private Publisher $publisher)
    {
    }

    /**
     * Publish ORDER.CHECKOUT.REQUESTED event
     */
    public function publishCheckoutRequested(Order $order): void
    {
        try {
            $payload = [
                'order_id'     => $order->id,
                'user_id'      => $order->user_id,
                'total_amount' => $order->total,
                'items'        => $order->items->map(fn($item) => [
                    'product_id' => $item->product_id,
                    'quantity'   => $item->qty,
                    'price'      => $item->price,
                ])->toArray(),
                'timestamp'    => now()->toIso8601String(),
            ];

            Publisher::publish(EventTopic::ORDER_CHECKOUT_REQUESTED, $payload);

            Log::info('Order checkout requested', ['order_id' => $order->id]);
        } catch (Exception $e) {
            Log::error('Failed to publish checkout event', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            throw new EventPublishingException('Failed to publish checkout event', previous: $e);
        }
    }

    /**
     * Publish PAYMENT.REQUESTED event
     */
    public function publishPaymentRequested(Order $order): void
    {
        try {
            $payload = [
                'order_id'     => $order->id,
                'total_amount' => $order->total,
                'currency'     => 'USD',
                'timestamp'    => now()->toIso8601String(),
            ];

            Publisher::publish(EventTopic::PAYMENT_REQUESTED, $payload);

            Log::info('Payment requested for order', ['order_id' => $order->id]);
        } catch (Exception $e) {
            Log::error('Failed to publish payment event', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            throw new EventPublishingException('Failed to publish payment event', previous: $e);
        }
    }

    /**
     * Handle PRODUCT.STOCK.RESERVED event
     */
    public function handleProductValidated(Order $order): void
    {
        try {
            $order->status = $order->status; // Already reserved via event consumer
            $order->save();

            Log::info('Product stock validated', ['order_id' => $order->id]);

            // Publish payment request event
            $this->publishPaymentRequested($order);
        } catch (\Exception $e) {
            Log::error('Failed to handle product validation', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle PRODUCT.STOCK.REJECTED event
     */
    public function handleProductRejected(string $orderId): void
    {
        try {
            Log::info('Product stock rejected', ['order_id' => $orderId]);

            // Update order status to rejected
            $order = Order::findOrFail($orderId);
            $order->update(['status' => \App\Enums\OrderStatus::REJECTED]);

            Log::info('Order marked as rejected', ['order_id' => $orderId]);
        } catch (\Exception $e) {
            Log::error('Failed to handle product rejection', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle PAYMENT.AUTHORIZED event
     */
    public function handlePaymentSucceeded(string $orderId): void
    {
        try {
            $order = Order::findOrFail($orderId);
            $order->update(['status' => \App\Enums\OrderStatus::CONFIRMED]);

            Log::info('Order payment succeeded and confirmed', ['order_id' => $orderId]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payment success', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle PAYMENT.FAILED event
     */
    public function handlePaymentFailed(string $orderId): void
    {
        try {
            $order = Order::findOrFail($orderId);
            $order->update(['status' => \App\Enums\OrderStatus::FAILED]);

            Log::info('Order payment failed', ['order_id' => $orderId]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payment failure', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
