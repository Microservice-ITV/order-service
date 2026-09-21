<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order represents a confirmed order (new "orders" table).
 * This is created after checkout session is confirmed with payment and reservation.
 */
class Order extends Model
{
    protected $primaryKey = 'order_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'checkout_id',
        'reservation_id',
        'payment_id',
        'status_id',
        'courier_code',
        'courier_name',
        'courier_phone',
        'tracking_number',
        'tracking_url',
        'shipment_created_at',
    ];

    protected $casts = [
        'status_id' => 'integer',
        'shipment_created_at' => 'datetime',
    ];

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }
}
