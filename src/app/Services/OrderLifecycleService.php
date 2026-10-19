<?php

namespace App\Services;

use App\Contracts\OrderInterface;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\ShopOrder;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\DB;

final class OrderLifecycleService
{
    public function __construct(private readonly OrderInterface $orders) {}

    public function cancelShippedOrder(string $orderId, string $reason): Order
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('A cancellation reason is required', 422);
        }

        $wasCancelled = DB::transaction(function () use ($orderId, $reason): bool {
            $order = $this->lockOrder($orderId);
            if ((int) $order->order_status === OrderStatus::CANCELLED->value) {
                return false;
            }
            if ((int) $order->order_status === OrderStatus::RETURNED->value) {
                throw new BusinessException('A returned order cannot be cancelled', 409);
            }

            $shopOrders = ShopOrder::where('order_id', $orderId)
                ->lockForUpdate()
                ->get();
            if ($shopOrders->isEmpty()) {
                if ((int) $order->order_status !== OrderStatus::SHIPPED->value) {
                    throw new BusinessException('Only shipped orders can be cancelled', 409, [
                        'current_status' => OrderStatus::tryFrom((int) $order->order_status)?->name,
                    ]);
                }
            } elseif ($shopOrders->contains(
                static fn (ShopOrder $shopOrder): bool => (int) $shopOrder->order_status !== OrderStatus::SHIPPED->value,
            )) {
                throw new BusinessException('Every shop shipment must be SHIPPED before cancelling this order', 409);
            }

            $cancelledAt = now();
            $returnDeadlineAt = $cancelledAt->copy()->addDays((int) config('order.return_window_days', 7));
            $order->update([
                'order_status' => OrderStatus::CANCELLED->value,
                'cancellation_reason' => $reason,
                'cancelled_at' => $cancelledAt,
                'return_deadline_at' => $returnDeadlineAt,
                'updated_at' => $cancelledAt,
            ]);

            if ($shopOrders->isNotEmpty()) {
                ShopOrder::where('order_id', $orderId)->update([
                    'order_status' => OrderStatus::CANCELLED->value,
                    'updated_at' => $cancelledAt,
                ]);
            }

            return true;
        });

        /** @var Order $order */
        $order = $this->orders->findById($orderId);
        if (!$order) {
            throw new BusinessException('Order not found', 404);
        }

        if ($wasCancelled) {
            $this->publishLifecycleEvent($order, EventTopic::ORDER_CANCELLED, [
                'status' => OrderStatus::CANCELLED->name,
                'reason' => $order->cancellation_reason,
                'cancelled_at' => $order->cancelled_at?->toIso8601String(),
                'return_deadline_at' => $order->return_deadline_at?->toIso8601String(),
            ]);
        }

        return $order;
    }

    public function markOrderReturned(string $orderId, string $reason): Order
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('A return reason is required', 422);
        }

        $wasReturned = DB::transaction(function () use ($orderId, $reason): bool {
            $order = $this->lockOrder($orderId);
            if ((int) $order->order_status === OrderStatus::RETURNED->value) {
                return false;
            }
            if ((int) $order->order_status !== OrderStatus::CANCELLED->value || !$order->cancelled_at) {
                throw new BusinessException('Only cancelled orders can be marked returned', 409);
            }

            $returnDeadline = $order->return_deadline_at
                ?? $order->cancelled_at->copy()->addDays((int) config('order.return_window_days', 7));
            if (now()->greaterThan($returnDeadline)) {
                throw new BusinessException('The one-week return window for this order has expired', 409, [
                    'return_deadline_at' => $returnDeadline->toIso8601String(),
                ]);
            }

            $shopOrders = ShopOrder::where('order_id', $orderId)
                ->lockForUpdate()
                ->get();
            if ($shopOrders->contains(
                static fn (ShopOrder $shopOrder): bool => (int) $shopOrder->order_status !== OrderStatus::CANCELLED->value,
            )) {
                throw new BusinessException('All shop shipments must be cancelled before marking the order returned', 409);
            }

            $returnedAt = now();
            $order->update([
                'order_status' => OrderStatus::RETURNED->value,
                'return_reason' => $reason,
                'returned_at' => $returnedAt,
                'updated_at' => $returnedAt,
            ]);

            if ($shopOrders->isNotEmpty()) {
                ShopOrder::where('order_id', $orderId)->update([
                    'order_status' => OrderStatus::RETURNED->value,
                    'updated_at' => $returnedAt,
                ]);
            }

            return true;
        });

        /** @var Order $order */
        $order = $this->orders->findById($orderId);
        if (!$order) {
            throw new BusinessException('Order not found', 404);
        }

        if ($wasReturned) {
            $this->publishLifecycleEvent($order, EventTopic::ORDER_RETURNED, [
                'status' => OrderStatus::RETURNED->name,
                'reason' => $order->return_reason,
                'returned_at' => $order->returned_at?->toIso8601String(),
            ]);
        }

        return $order;
    }

    private function lockOrder(string $orderId): Order
    {
        $order = Order::where('order_id', $orderId)->lockForUpdate()->first();
        if (!$order) {
            throw new BusinessException('Order not found', 404);
        }

        return $order;
    }

    /** @param array<string, string|null> $fields */
    private function publishLifecycleEvent(Order $order, EventTopic $topic, array $fields): void
    {
        $order->loadMissing('checkoutSession');
        $checkout = $order->checkoutSession;
        $correlationId = (string) ($checkout?->correlation_id ?? $order->checkout_id ?? $order->order_id);

        Publisher::publishStatic($topic, EventPayloadBuilder::build($topic, $correlationId, [
            'order_id' => $order->order_id,
            'checkout_id' => $order->checkout_id,
            'reservation_id' => $order->reservation_id,
            'user_id' => (string) ($order->user_id ?? $checkout?->user_id ?? ''),
            ...$fields,
            'shop_breakdown' => app(OrderService::class)->shopBreakdown($order->order_id),
            'updatedAt' => now()->utc()->toIso8601String(),
        ]));
    }
}
