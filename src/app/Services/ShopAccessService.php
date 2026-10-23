<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CheckoutItem;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\ShopOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Shop-membership checks against auth-service's access context
 * (GET /api/v1/me/access-context with the caller's bearer token).
 */
final class ShopAccessService
{
    /** Roles allowed to ship one shop's portion of an order. */
    public const SHIPPER_ROLES = ['shop_owner', 'shop_admin', 'staff'];

    /** Whole-order (legacy) shipments stay with shop owners / admins. */
    public const MANAGER_ROLES = ['shop_owner', 'shop_admin'];

    /**
     * @throws BusinessException 401 / 403 / 503
     */
    public function assertCanShip(string $authorization, string $orderId, ?string $shopId): void
    {
        ['user_id' => $userId, 'roles' => $roles] = $this->accessContext($authorization);

        if ($shopId !== null) {
            $role = $roles[$shopId] ?? null;
            if (!in_array($role, self::SHIPPER_ROLES, true)) {
                throw new BusinessException('You are not allowed to ship orders for this shop', 403);
            }
            // Staff may only ship orders they placed themselves.
            if ($role === 'staff' && !$this->placedBy($orderId, $userId)) {
                throw new BusinessException('Staff can only ship orders they placed', 403);
            }
            return;
        }

        $shopIds = $this->orderShopIds($orderId);
        if ($shopIds === []) {
            throw new BusinessException('You are not allowed to ship this order', 403);
        }
        foreach ($shopIds as $orderShopId) {
            if (!in_array($roles[$orderShopId] ?? null, self::MANAGER_ROLES, true)) {
                throw new BusinessException('You are not allowed to ship this order', 403);
            }
        }
    }

    /**
     * @return array{user_id: string, roles: array<string, string>} roles: shop_id => role code, active memberships only
     * @throws BusinessException
     */
    public function accessContext(string $authorization): array
    {
        $baseUrl = rtrim((string) config('services.auth.url'), '/');

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->withHeaders(['Authorization' => $authorization])
                ->get("{$baseUrl}/api/v1/me/access-context");
        } catch (ConnectionException) {
            throw new BusinessException('Unable to verify shop membership', 503);
        }

        if ($response->status() === 401) {
            throw new BusinessException('Unauthorized', 401);
        }
        if (!$response->successful()) {
            throw new BusinessException('Unable to verify shop membership', 503);
        }

        $roles = [];
        foreach ((array) $response->json('data.access_context.shops', []) as $shop) {
            $shopId = trim((string) ($shop['shop_id'] ?? ''));
            $status = strtolower((string) ($shop['membership_status'] ?? ''));
            $role = strtolower((string) ($shop['role']['code'] ?? ''));
            if ($shopId !== '' && $status === 'active' && $role !== '') {
                $roles[$shopId] = $role;
            }
        }

        return [
            'user_id' => trim((string) $response->json('data.user.id', '')),
            'roles' => $roles,
        ];
    }

    private function placedBy(string $orderId, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        $order = Order::where('order_id', $orderId)->first(['user_id', 'checkout_id']);
        if (!$order) {
            return false;
        }

        $placedBy = trim((string) ($order->user_id ?? ''));
        if ($placedBy === '' && $order->checkout_id) {
            $placedBy = trim((string) CheckoutSession::where('id', $order->checkout_id)->value('user_id'));
        }

        return $placedBy !== '' && hash_equals($placedBy, $userId);
    }

    /** @return list<string> */
    private function orderShopIds(string $orderId): array
    {
        $shopIds = ShopOrder::where('order_id', $orderId)->pluck('shop_id');

        if ($shopIds->isEmpty()) {
            $checkoutId = Order::where('order_id', $orderId)->value('checkout_id');
            $shopIds = $checkoutId
                ? CheckoutItem::where('checkout_id', $checkoutId)->pluck('shop_id')
                : collect();
        }

        return $shopIds
            ->map(static fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
