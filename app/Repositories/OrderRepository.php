<?php

namespace App\Repositories;

use App\Contracts\OrderInterface;
use App\Models\Order;
use App\Enums\OrderStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderRepository extends BaseRepository implements OrderInterface
{
    protected string $cachePrefix = 'order:';
    protected int $cacheTTL = 3600; // 1 hour

    public function __construct(Order $order)
    {
        $this->setModel($order);
    }

    /**
     * Create a pending order with items (atomic transaction)
     */
    public function createPendingOrder(
        int $userId,
        array $items,
        float $total
    ): Order {
        return DB::transaction(function () use ($userId, $items, $total) {
            $order = $this->model->create([
                'id' => \Illuminate\Support\Str::uuid(),
                'status' => OrderStatus::CREATED,
                'total' => $total,
            ]);

            if (!empty($items)) {
                $order->items()->createMany($items);
            }

            return $order;
        });
    }

    /**
     * Get paginated orders with eager loading
     */
    public function paginateByUser(int $userId): LengthAwarePaginator
    {
        return $this->model
            ->with('items')
            ->recent()
            ->paginate(15);
    }

    /**
     * Get order by ID with items
     */
    public function getByUserAndId(int $userId, int $orderId): Order
    {
        return $this->model
            ->with('items')
            ->findOrFail($orderId);
    }

    /**
     * Get order by UUID
     */
    public function getByUuid(string $uuid): ?Order
    {
        return Cache::remember(
            "{$this->cachePrefix}{$uuid}",
            $this->cacheTTL,
            fn() => $this->model->find($uuid)
        );
    }

    /**
     * Update order status
     */
    public function updateStatus(string $orderId, OrderStatus $status): bool
    {
        $result = $this->model
            ->where('id', $orderId)
            ->update(['status' => $status]);

        // Invalidate cache
        Cache::forget("{$this->cachePrefix}{$orderId}");

        return $result > 0;
    }

    /**
     * Mark order as paid
     */
    public function markAsPaid(int $orderId): void
    {
        $this->updateStatus((string)$orderId, OrderStatus::CONFIRMED);
    }

    /**
     * Mark order as cancelled
     */
    public function markAsCancelled(int $orderId): void
    {
        $this->updateStatus((string)$orderId, OrderStatus::REJECTED);
    }

    /**
     * Mark order as failed
     */
    public function markAsFailed(string $orderId): bool
    {
        return $this->updateStatus($orderId, OrderStatus::FAILED);
    }

    /**
     * Get orders by status
     */
    public function getByStatus(OrderStatus $status): array
    {
        return $this->model
            ->where('status', $status)
            ->get()
            ->toArray();
    }

    /**
     * Get pending payment orders
     */
    public function getPendingOrders(): array
    {
        return $this->model
            ->pending()
            ->get()
            ->toArray();
    }

    /**
     * Check if order exists
     */
    public function exists(string $orderId): bool
    {
        return $this->model->where('id', $orderId)->exists();
    }
}

