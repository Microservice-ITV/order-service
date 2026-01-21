<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Order extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'status', 'total'];

    protected $casts = [
        'status' => OrderStatus::class,
        'total' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all order items
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Scope: Get confirmed orders
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::CONFIRMED);
    }

    /**
     * Scope: Get pending orders
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::PENDING_PAYMENT);
    }

    /**
     * Scope: Get failed orders
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', [OrderStatus::REJECTED, OrderStatus::FAILED]);
    }

    /**
     * Scope: Recent orders first
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Check if order is in final state
     */
    public function isFinal(): bool
    {
        return in_array($this->status, [
            OrderStatus::CONFIRMED,
            OrderStatus::REJECTED,
            OrderStatus::FAILED,
        ]);
    }

    /**
     * Get total items count
     */
    public function getItemsCountAttribute(): int
    {
        return $this->items()->count();
    }
}

