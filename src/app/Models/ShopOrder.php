<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ShopOrder represents a shop-level order summary.
 * Links an order to a specific shop with its own financial totals and statuses.
 */
class ShopOrder extends Model
{
    protected $table = 'shop_orders';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_id',
        'shop_id',
        'provider_id',
        'subtotal',
        'shipping_total',
        'discount_total',
        'bundle_discount',
        'tax_total',
        'grand_total',
        'order_status',
        'payment_status',
        'tracking_number',
        'courier_code',
        'courier_name',
        'courier_phone',
        'tracking_url',
        'shipment_created_at',
    ];

    protected $casts = [
        'order_status' => 'integer',
        'subtotal' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'bundle_discount' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'shipment_created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function orderStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'order_status');
    }
}
