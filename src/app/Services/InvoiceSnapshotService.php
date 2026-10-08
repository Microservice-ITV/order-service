<?php

namespace App\Services;

use App\Contracts\CheckoutSessionInterface;
use App\Contracts\OrderInterface;
use App\Models\CheckoutSession;
use App\Models\Order;
use Illuminate\Support\Carbon;
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
    *   customerName: string|null,
    *   customerEmail: string|null,
    *   customerPhone: string|null,
    *   billingAddress: array<string, mixed>|null,
    *   shippingAddress: array<string, mixed>|null,
    *   paymentStatus: string|null,
    *   paymentMethod: string|null,
     *   items: list<array{
     *     orderItemId: string,
     *     productId: string,
     *     productName: string|null,
     *     productSku: string|null,
     *     imageUrl: string|null,
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

        return $this->buildSnapshot($order, $checkout);
    }

    /**
     * Fetch invoice-eligible order snapshots in stable keyset-paginated batches.
     *
     * @return array{orders: list<array{snapshot: array<string, mixed>, createdAt: string, cursorAfter: string}>, nextCursor: string|null}
     */
    public function listInvoiceCandidates(int $limit, ?string $cursor): array
    {
        $query = Order::query()
            ->whereIn('payment_status', ['PAID', 'COD'])
            ->with(['items', 'checkoutSession.items'])
            ->orderBy('created_at')
            ->orderBy('order_id');

        $decodedCursor = $this->decodeCursor($cursor);
        if ($decodedCursor !== null) {
            $query->where(function ($query) use ($decodedCursor): void {
                $query->where('created_at', '>', $decodedCursor['createdAt'])
                    ->orWhere(function ($query) use ($decodedCursor): void {
                        $query->where('created_at', '=', $decodedCursor['createdAt'])
                            ->where('order_id', '>', $decodedCursor['orderId']);
                    });
            });
        }

        $orders = $query->limit($limit + 1)->get();
        $hasMore = $orders->count() > $limit;
        $page = $orders->take($limit);
        $candidates = [];

        foreach ($page as $order) {
            /** @var CheckoutSession|null $checkout */
            $checkout = $order->checkoutSession;
            if ($checkout === null) {
                continue;
            }

            $createdAt = $order->created_at;
            if ($createdAt === null) {
                continue;
            }

            $candidates[] = [
                'snapshot' => $this->buildSnapshot($order, $checkout),
                'createdAt' => $createdAt->toIso8601String(),
                'cursorAfter' => $this->encodeCursor([
                    'createdAt' => $createdAt->toDateTimeString(),
                    'orderId' => (string) $order->order_id,
                ]),
            ];
        }

        $lastOrder = $page->last();
        $nextCursor = $hasMore && $lastOrder !== null && $lastOrder->created_at !== null
            ? $this->encodeCursor([
                'createdAt' => $lastOrder->created_at->toDateTimeString(),
                'orderId' => (string) $lastOrder->order_id,
            ])
            : null;

        return [
            'orders' => $candidates,
            'nextCursor' => $nextCursor,
        ];
    }

    /**
     * @param array{createdAt: string, orderId: string} $cursor
     */
    private function encodeCursor(array $cursor): string
    {
        return rtrim(strtr(base64_encode(json_encode($cursor, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{createdAt: string, orderId: string}|null */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || trim($cursor) === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            throw new HttpException(422, 'Invalid invoice candidate cursor');
        }

        try {
            $value = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            if (
                ! is_array($value)
                || ! is_string($value['createdAt'] ?? null)
                || ! is_string($value['orderId'] ?? null)
                || trim($value['orderId']) === ''
            ) {
                throw new HttpException(422, 'Invalid invoice candidate cursor');
            }
            $createdAt = Carbon::parse($value['createdAt'])->toDateTimeString();
        } catch (\Throwable $error) {
            if ($error instanceof HttpException) {
                throw $error;
            }
            throw new HttpException(422, 'Invalid invoice candidate cursor');
        }

        return ['createdAt' => $createdAt, 'orderId' => $value['orderId']];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(Order $order, CheckoutSession $checkout): array
    {
        $items = $checkout->items;
        $orderItems = $order->items;
        $deliveryAddress = is_array($checkout->delivery_address)
            ? $checkout->delivery_address
            : [];
        $billingAddress = is_array($deliveryAddress['billing_address'] ?? null)
            ? $deliveryAddress['billing_address']
            : null;
        $shippingAddress = $deliveryAddress !== [] ? $deliveryAddress : null;

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
            'customerName' => $this->firstNonEmptyString($deliveryAddress, ['full_name', 'recipient_name', 'name']),
            'customerEmail' => $this->firstNonEmptyString($deliveryAddress, ['email', 'recipient_email']),
            'customerPhone' => $this->firstNonEmptyString($deliveryAddress, ['phone', 'phone_number', 'recipient_phone']),
            'billingAddress' => $billingAddress,
            'shippingAddress' => $shippingAddress,
            'paymentStatus' => (string) ($order->payment_status ?? ''),
            'paymentMethod' => (string) ($checkout->payment_method ?? ''),

            'items' => $orderItems
                ->map(static fn($item): array => [
                    'orderItemId' => (string) $item->id,
                    'productId' => (string) $item->product_id,
                    'productName' => $item->product_title !== null
                        ? (string) $item->product_title
                        : null,
                    'productSku' => $item->product_sku !== null
                        ? (string) $item->product_sku
                        : null,
                    'imageUrl' => $item->image_url !== null
                        ? (string) $item->image_url
                        : null,
                    'quantity' => (int) $item->qty,
                    'unitPrice' => (string) $item->price,
                    'lineTotal' => $item->subtotal !== null
                        ? (string) $item->subtotal
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

            'taxAmount' => number_format(
                (float) ($order->tax_amount ?? $checkout->tax_amount ?? 0),
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

    /** @param array<string, mixed> $source @param list<string> $keys */
    private function firstNonEmptyString(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
