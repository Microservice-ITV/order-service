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
        'user_id',
        'reservation_id',
        'payment_id',
        'order_status',
        'currency',
        'subtotal',
        'shipping_total',
        'discount_total',
        'bundle_discount',
        'tax_amount',
        'taxable_amount',
        'tax_enabled',
        'grand_total',
        'cash_paid_amount',
        'cash_refund_amount',
        'payment_status',
        'courier_code',
        'courier_name',
        'courier_phone',
        'tracking_number',
        'tracking_url',
        'shipment_created_at',
    ];

    protected $casts = [
        'order_status' => 'integer',
        'user_id' => 'string',
        'shipment_created_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'bundle_discount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'tax_enabled' => 'boolean',
        'grand_total' => 'decimal:2',
        'cash_paid_amount' => 'decimal:2',
        'cash_refund_amount' => 'decimal:2',
    ];

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id');
    }

    public function orderStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'order_status');
    }
}
