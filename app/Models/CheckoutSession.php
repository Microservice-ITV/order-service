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

    protected $fillable = ['id', 'status_id', 'total', 'failure_reason', 'idempotency_key', 'correlation_id', 'reservation_id', 'payment_id'];

    protected $casts = [
        'status_id' => OrderStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CheckoutItem::class, 'checkout_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class, 'checkout_id');
    }
}
