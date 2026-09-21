<?php

namespace App\Services;

use App\Contracts\CheckoutSessionInterface;
use App\Contracts\OrderInterface;
use App\Models\CheckoutSession;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InvoiceSnapshotService
{
    public function __construct(
        private readonly OrderInterface $orders,
        private readonly CheckoutSessionInterface $checkoutSessions
    ) {}

    /**
     * @return array{
     *   orderId: string,
     *   checkoutId: string,
     *   customerId: string,
     *   items: list<array{
     *     orderItemId: string,
     *     productId: string,
     *     productName: string|null,
     *     productSku: string|null,
     *     quantity: int,
     *     unitPrice: string,
     *     lineTotal: string|null,
     *     shopId: string|null
     *   }>,
     *   currency: string|null,
     *   subtotal: string,
     *   deliveryFee: string,
     *   promotionDiscountTotal: string,
     *   grandTotal: string
     * }
     */
    public function buildForOrder(string $orderId): array
    {
        $order = $this->orders->findByOrderId($orderId);

        if ($order === null) {
            throw new HttpException(404, 'Order not found');
        }

        /** @var CheckoutSession|null $checkout */
        $checkout = $this->checkoutSessions->findWithItemsById(
            (string) $order->checkout_id
        );

        if ($checkout === null) {
            throw new HttpException(404, 'Checkout session not found');
        }

        $items = $checkout->items;

        $currency = $items
            ->pluck('currency')
            ->filter(
                static fn($value): bool =>
                is_string($value) && trim($value) !== ''
            )
            ->first();

        /*
         * Calculate subtotal from checkout item line totals.
         *
         * We intentionally do not use checkout.total here because
         * checkout.total already includes shipping and promotion discount.
         */
        $subtotal = $items->sum(
            static fn($item): float =>
            (float) ($item->line_total ?? 0)
        );

        return [
            'orderId' => (string) $order->order_id,
            'checkoutId' => (string) $checkout->id,
            'customerId' => (string) $checkout->user_id,

            'items' => $items
                ->map(static fn($item): array => [
                    'orderItemId' => (string) $item->id,
                    'productId' => (string) $item->product_id,
                    'productName' => $item->product_title !== null
                        ? (string) $item->product_title
                        : null,
                    'productSku' => $item->product_sku !== null
                        ? (string) $item->product_sku
                        : null,
                    'quantity' => (int) $item->qty,
                    'unitPrice' => (string) $item->price,
                    'lineTotal' => $item->line_total !== null
                        ? (string) $item->line_total
                        : null,
                    'shopId' => $item->shop_id !== null
                        ? (string) $item->shop_id
                        : null,
                ])
                ->values()
                ->all(),

            'currency' => $currency !== null
                ? (string) $currency
                : null,

            'subtotal' => number_format($subtotal, 2, '.', ''),

            'deliveryFee' => number_format(
                (float) $checkout->shipping_total,
                2,
                '.',
                ''
            ),

            'promotionDiscountTotal' => number_format(
                (float) $checkout->promotion_discount_total,
                2,
                '.',
                ''
            ),

            /*
             * CheckoutSession::total is the authoritative final checkout
             * amount and should be used as the invoice grand total.
             */
            'grandTotal' => number_format(
                (float) $checkout->total,
                2,
                '.',
                ''
            ),
        ];
    }
}
