<?php

namespace App\Console\Commands;

use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Logging\LogMessage;
use App\Models\CheckoutItem;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\ShopOrder;
use App\Repositories\SagaRepository;
use App\Services\CheckoutService;
use App\Services\EventPayloadBuilder;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RabbitMQ\BaseConsumer;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConsumePaymentSucceeded extends BaseConsumer
{
    /** Platform commission charged per shop, on that shop's own order amount. */
    private const PLATFORM_FEE_RATE = 0.03;

    protected $signature = 'consume:payment-succeeded';
    protected $description = 'Consume payment succeeded events';

    protected function eventName(): string
    {
        return EventTopic::PAYMENT_COMPLETED->value;
    }

    protected function queue(): string
    {
        return config('rabbitmq.queues.payment_completed');
    }

    protected function routingKeys(): array
    {
        return [EventTopic::PAYMENT_COMPLETED->value];
    }

    public function process(array $payload): void
    {
        $data = $payload['data'] ?? [];

        // Single source of truth for correlation ID
        $correlationId = $payload['correlation_id']
            ?? $data['correlation_id']
            ?? null;

        $checkoutId = $data['checkout_id'] ?? null;
        $paymentId  = $data['payment_id'] ?? null;
        $transactionId = $data['transaction_id'] ?? null;
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $currency = isset($data['currency']) ? (string) $data['currency'] : null;
        $sourceUpdatedAt = $data['updatedAt'] ?? null;
        $reservationId = $data['reservation_id'] ?? null;

        if (!$checkoutId || !$correlationId) {
            logger()->warning(LogMessage::PAYMENT_COMPLETED_INVALID_PAYLOAD, [
                'payload' => $payload,
            ]);
            return;
        }

        logger()->info(LogMessage::PAYMENT_COMPLETED_RECEIVED, [
            'checkout_id'    => $checkoutId,
            'payment_id'     => $paymentId,
            'reservation_id' => $reservationId,
            'correlation_id' => $correlationId,
        ]);

        try {
            $checkoutService = app(CheckoutService::class);
            $orderService = app(OrderService::class);

            // Validate checkout exists before processing payment
            $checkout = $checkoutService->findById($checkoutId);
            if (!$checkout) {
                logger()->warning(LogMessage::PAYMENT_COMPLETED_INVALID_PAYLOAD, [
                    'checkout_id' => $checkoutId,
                    'reason'      => 'CHECKOUT_NOT_FOUND',
                ]);
                return;
            }

            $existingOrder = \App\Models\Order::query()->where('checkout_id', $checkoutId)->first();
            if ($existingOrder || in_array($checkout->status_id, [OrderStatus::PAID, OrderStatus::CONFIRMED], true)) {
                if ($paymentId && $existingOrder) {
                    app(PaymentService::class)->attachOrder($paymentId, $existingOrder->order_id);
                }
                logger()->info(LogMessage::PAYMENT_COMPLETED_ALREADY_PROCESSED, [
                    'checkout_id' => $checkoutId,
                    'payment_id'  => $paymentId,
                ]);
                return;
            }

            if ($checkout->isExpired()) {
                $checkoutService->fail($checkoutId, 'CHECKOUT_EXPIRED');

                logger()->warning(LogMessage::PAYMENT_COMPLETED_CHECKOUT_EXPIRED, [
                    'checkout_id' => $checkoutId,
                    'payment_id'  => $paymentId,
                    'expire_at'   => $checkout->getExpireAtIso(),
                ]);
                return;
            }

            DB::transaction(function () use (
                $checkoutId,
                $paymentId,
                $reservationId,
                $correlationId,
                $checkoutService,
                $orderService,
                $checkout,
                &$order
            ) {
                /**
                 * Confirm checkout (IDEMPOTENT)
                 * - Must atomically check & update state
                 */
                $confirmed = $checkoutService->confirm($checkoutId);

                if (!$confirmed) {
                    logger()->warning(
                        LogMessage::PAYMENT_CONFIRM_IGNORED_INVALID_STATE,
                        ['checkout_id' => $checkoutId]
                    );
                    return;
                }

                /**
                 *  Create order (idempotent by checkout_id UNIQUE)
                 */
                $order = $orderService->createOrder(
                    checkout: $checkout,
                    correlationId: $correlationId,
                    reservationId: $reservationId
                );

                /**
                 * Snapshot checkout line items into order_items so the order
                 * carries its own per-product records (same transaction).
                 */
                $orderService->snapshotCheckoutItems($checkout, $order);

                /**
                 * Attach payment (optional, idempotent)
                 */
                if ($paymentId) {
                    $orderService->attachPayment(
                        checkoutId: $checkoutId,
                        paymentId: $paymentId
                    );
                }

                $orderService->markPaid($checkout);
            });

            // Update payment_status on orders and shop_orders for non-COD payments
            if (isset($order)) {
                $isCod = ($checkout->payment_method ?? '') === 'cod';
                if (!$isCod) {
                    $orderService->markShopOrdersPaid($order->order_id, false);
                }
            }

            if (!isset($order)) {
                // Checkout already processed — safe to ignore
                return;
            }

            if ($paymentId) {
                app(PaymentService::class)->attachOrder($paymentId, $order->order_id);
            }

            // Create payment_allocations for each shop_order
            if ($paymentId) {
                $this->createPaymentAllocations($paymentId, $order->order_id);
            }

            // Advance the saga tracker: payment processing succeeded.
            $sagaRepository = app(SagaRepository::class);
            $saga = $sagaRepository->findByOrderId($checkoutId);
            if ($saga && !in_array('PAYMENT_PROCESSING', $saga->completed_steps ?? [], true)) {
                $sagaRepository->completeStep($saga->saga_id, 'PAYMENT_PROCESSING');

                logger()->info('SAGA_STEP_COMPLETED_FROM_PAYMENT_SUCCEEDED', [
                    'saga_id'  => $saga->saga_id,
                    'step'     => 'PAYMENT_PROCESSING',
                    'checkout_id' => $checkoutId,
                    'order_id' => $order->order_id,
                ]);
            }

            logger()->info(LogMessage::ORDER_CREATED_AFTER_PAYMENT, [
                'order_id'       => $order->order_id,
                'checkout_id'    => $checkoutId,
                'payment_id'     => $paymentId,
                'correlation_id' => $correlationId,
            ]);

            /**
             * Publish ORDER_PAID event
             * Downstream services (stock, shipping, email, etc.)
             * Refresh checkout so promotion_snapshot reflects the latest address/promo quote
             * (the in-memory model may have been loaded before Place Order updated it).
             */
            $checkout = $checkoutService->findById($checkoutId) ?? $checkout;
            $checkout->loadMissing('items');
            $lineItems = $checkout->items->map(static function ($item): array {
                return [
                    'name' => (string) ($item->product_title ?? 'Order item'),
                    'quantity' => (int) $item->qty,
                    'unitPrice' => number_format((float) $item->price, 2, '.', ''),
                    'total' => number_format((float) $item->line_total, 2, '.', ''),
                ];
            })->values()->all();
            $promotionSnapshot = is_array($checkout->promotion_snapshot)
                ? $checkout->promotion_snapshot
                : [];

            /**
             * Line items are forwarded so downstream projections (e.g.
             * ecommerce-read-service order_reads) can store per-product
             * records for the order. Mirrors the order_items snapshot.
             */
            $items = $checkout->items
                ->map(function (CheckoutItem $item): array {
                    $unitPrice = (float) ($item->price ?? 0);
                    $subtotal = (float) ($item->line_total ?? $unitPrice * (int) ($item->qty ?? 0));

                    return [
                        'productId'      => (string) $item->product_id,
                        'variantId'      => $item->variant_id !== null && $item->variant_id !== ''
                            ? (string) $item->variant_id
                            : null,
                        'sku'            => $item->product_sku ?? '',
                        'slug'           => $item->product_slug ?? '',
                        'name'           => $item->product_title ?? '',
                        'description'    => $item->product_description ?? '',
                        'imageUrl'       => $item->image_url ?? '',
                        'quantity'       => (int) ($item->qty ?? 0),
                        'unitPrice'      => $unitPrice,
                        'subtotal'       => $subtotal,
                        'currency'       => $item->currency ?? 'USD',
                        'shopId'         => $item->shop_id !== null ? (string) $item->shop_id : null,
                        'categoryId'     => $item->category_id !== null ? (string) $item->category_id : null,
                        'availableStock' => (int) ($item->available_stock ?? 0),
                    ];
                })
                ->values()
                ->all();
            $subtotal = (float) array_sum(array_column($items, 'subtotal'));

            $payload = EventPayloadBuilder::build(EventTopic::ORDER_PAID, $correlationId, [
                'order_id'       => $order->order_id,
                'checkout_id'    => $checkoutId,
                'user_id'        => (string) $checkout->user_id,
                'status'         => 'ORDER_PAID',
                'payment_id'     => $paymentId,
                'transaction_id' => $transactionId,
                'amount'         => $amount,
                'currency'       => $currency ?? 'USD',
                'subtotal'       => $subtotal,
                'items'          => $items,
                'reservation_id' => $order->reservation_id,
                'deliveryAddress' => $checkout->delivery_address,
                'line_items' => $lineItems,
                'expire_at'      => $checkout->getExpireAtIso(),
                'updatedAt'      => is_string($sourceUpdatedAt) && $sourceUpdatedAt !== ''
                    ? $sourceUpdatedAt
                    : now()->utc()->toIso8601String(),
                // Promotion usage commit payload (consumed by promotion-service)
                'promotion_ids' => array_values($promotionSnapshot['promotion_ids'] ?? []),
                'promotion_discount_total' => (float) ($checkout->promotion_discount_total ?? 0),
                'applied_rewards' => array_values($promotionSnapshot['applied_rewards'] ?? []),
                'promo_code' => $promotionSnapshot['promo_code'] ?? null,
                // Per-shop attribution so the read side can render each shop's
                // own discount/shipping breakdown instead of re-splitting.
                'promotion_allocations' => array_values(is_array($promotionSnapshot['allocations'] ?? null) ? $promotionSnapshot['allocations'] : []),
                'shop_breakdown' => $orderService->shopBreakdown($order->order_id),
            ]);
            Publisher::publishStatic(EventTopic::ORDER_PAID, $payload);

            logger()->info('ORDER_PAID_EVENT_PUBLISHED', [
                'checkout_id' => $checkoutId,
                'order_id' => $order->order_id,
                'promotion_ids' => $payload['data']['promotion_ids'] ?? [],
                'promo_code' => $payload['data']['promo_code'] ?? null,
                'promotion_discount_total' => $payload['data']['promotion_discount_total'] ?? 0,
            ]);

        } catch (Throwable $e) {
            logger()->error(LogMessage::PAYMENT_COMPLETED_PROCESSING_FAILED, [
                'checkout_id'    => $checkoutId,
                'payment_id'     => $paymentId,
                'correlation_id' => $correlationId,
                'exception'      => $e,
            ]);

            // Let BaseConsumer decide retry / requeue behavior
            throw $e;
        }
    }

    /**
     * Create payment_allocations for each shop_order linked to the order.
     *
     * Every figure is the shop's OWN amount (no scaling by the order total):
     *  - order_amount   = shop grand_total (already nets its discount + shipping)
     *  - discount_share = shop discount_total (informational; reflected in order_amount)
     *  - shipping_share = shop shipping_total (buyer-paid, deducted from payout)
     *  - platform_fee   = 3% of this shop's order_amount
     *  - seller_amount  = order_amount - shipping_share - platform_fee
     */
    private function createPaymentAllocations(string $paymentId, string $orderId): void
    {
        $shopOrders = ShopOrder::where('order_id', $orderId)->get();

        if ($shopOrders->isEmpty()) {
            return;
        }

        // Get total payment amount from the payment service
        $paymentDetail = app(PaymentService::class)->getPaymentDetail($paymentId);
        $totalAmount = (float) ($paymentDetail['amount'] ?? 0);

        // Σ shop grand totals must equal the captured payment (invariant kept
        // by shop-order creation). Log drift instead of absorbing it silently.
        $sumGrand = round((float) $shopOrders->sum('grand_total'), 2);
        if ($totalAmount > 0 && abs($sumGrand - $totalAmount) > 0.02) {
            logger()->warning('PAYMENT_ALLOCATION_TOTAL_MISMATCH', [
                'payment_id'      => $paymentId,
                'order_id'        => $orderId,
                'payment_amount'  => $totalAmount,
                'shop_orders_sum' => $sumGrand,
            ]);
        }

        foreach ($shopOrders as $shopOrder) {
            $orderAmount = round((float) $shopOrder->grand_total, 2);
            $discountShare = round((float) $shopOrder->discount_total, 2);
            $shippingShare = round((float) $shopOrder->shipping_total, 2);
            $platformFee = round($orderAmount * self::PLATFORM_FEE_RATE, 2);
            $sellerAmount = round(max(0.0, $orderAmount - $shippingShare - $platformFee), 2);

            app(PaymentService::class)->createPaymentAllocation([
                'payment_id'     => $paymentId,
                'shop_order_id'  => $shopOrder->id,
                'shop_id'        => $shopOrder->shop_id,
                'order_amount'   => $orderAmount,
                'discount_share' => $discountShare,
                'shipping_share' => $shippingShare,
                'platform_fee'   => $platformFee,
                'seller_amount'  => $sellerAmount,
            ]);
        }
    }
}
