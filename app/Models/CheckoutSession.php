<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * CheckoutSession represents a checkout session (formerly "orders" table).
 * This is created when a user initiates checkout and contains cart items.
 */
/**
 * @property string $id
 * @property string $idempotency_key
 */
class CheckoutSession extends Model
{
    protected $table = 'checkout_sessions';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'status_id',
        'total',
        'failure_reason',
        'idempotency_key',
        'correlation_id',
        'reservation_id',
        'payment_id',
        'expire_at',
    ];

    protected $casts = [
        'status_id' => OrderStatus::class,
        'expire_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CheckoutItem::class, 'checkout_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class, 'checkout_id');
    }

    /**
     * Get expire_at as ISO 8601 string in UTC.
     * Returns null if expire_at is not set.
     */
    public function getExpireAtIso(): ?string
    {
        return $this->expire_at
            ? $this->expire_at->copy()->utc()->toIso8601String()
            : null;
    }

    /**
     * Check if the checkout session has expired.
     */
    public function isExpired(): bool
    {
        return $this->expire_at && $this->expire_at->isPast();
    }

    /**
     * Check if the checkout session is still valid (not expired).
     */
    public function isValid(): bool
    {
        return !$this->isExpired();
    }
}
