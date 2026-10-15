<?php

namespace App\Services;

use App\Models\CheckoutSession;
use App\Models\Order;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Builds per-shop payout split for Wallet Service (see wallet-service OrderWalletSplitResponse).
 */
final class WalletSplitService
{
    /**
     * @return array{
     *   order_id: string,
     *   checkout_id: string,
     *   currency: string,
     *   total: string,
     *   platform_commission: string,
     *   shops: list<array{shop_id: string, net_payout: string}>
     * }
     */
    public function buildForOrder(string $orderId, string $checkoutId): array
    {
        $order = Order::query()->where('order_id', $orderId)->first();
        if ($order === null) {
            throw new HttpException(404, 'Order not found');
        }

        if ($order->checkout_id !== $checkoutId) {
            throw new HttpException(422, 'checkout_id does not match this order');
        }

        /** @var CheckoutSession|null $checkout */
        $checkout = CheckoutSession::query()
            ->with(['items', 'shopShippings'])
            ->find($checkoutId);

        if ($checkout === null) {
            throw new HttpException(404, 'Checkout session not found');
        }

        $items = $checkout->items;
        if ($items->isEmpty()) {
            throw new HttpException(422, 'Checkout has no items');
        }

        $rate = (float) config('order.platform_commission_rate', 0.0);
        if ($rate < 0 || $rate > 1) {
            throw new HttpException(503, 'Invalid ORDER_PLATFORM_COMMISSION_RATE');
        }

        $currency = (string) ($items->first()->currency ?? 'USD');

        $itemSubtotal = 0.0;
        /** @var array<string, float> $byShop */
        $byShop = [];

        foreach ($items as $item) {
            $line = $item->line_total !== null
                ? (float) $item->line_total
                : (float) $item->price * (int) $item->qty;

            $sid = $item->shop_id !== null ? trim((string) $item->shop_id) : '';
            if ($sid === '') {
                throw new HttpException(422, 'Checkout items must include shop_id for wallet split');
            }

            $itemSubtotal += $line;
            $byShop[$sid] = ($byShop[$sid] ?? 0.0) + $line;
        }

        $itemSubtotal = round($itemSubtotal, 2);
        $shippingTotal = round((float) ($checkout->shipping_total ?? 0), 2);
        $grandTotal = round($itemSubtotal + $shippingTotal, 2);

        $platformCommission = round($itemSubtotal * $rate, 2);
        $distributable = round($itemSubtotal - $platformCommission, 2);

        $shopIds = array_keys($byShop);
        sort($shopIds);

        $shopsOut = [];
        $allocated = 0.0;
        $n = count($shopIds);

        foreach ($shopIds as $idx => $shopId) {
            $shopItemSum = $byShop[$shopId];
            $shippingRow = $checkout->shopShippings->firstWhere('shop_id', $shopId);
            $shipping = $shippingRow !== null ? (float) $shippingRow->shipping_fee : 0.0;

            if ($idx === $n - 1) {
                $itemNet = round($distributable - $allocated, 2);
            } else {
                $itemNet = $itemSubtotal > 0.0
                    ? round($distributable * ($shopItemSum / $itemSubtotal), 2)
                    : 0.0;
                $allocated += $itemNet;
            }

            $netPayout = round($itemNet + $shipping, 2);
            $shopsOut[] = [
                'shop_id' => $shopId,
                'net_payout' => $this->moneyString($netPayout),
            ];
        }

        return [
            'order_id' => $orderId,
            'checkout_id' => $checkoutId,
            'currency' => $currency,
            'total' => $this->moneyString($grandTotal),
            'platform_commission' => $this->moneyString($platformCommission),
            'shops' => $shopsOut,
        ];
    }

    private function moneyString(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
