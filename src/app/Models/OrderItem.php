<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderItem represents a line item of a confirmed order.
 * Stored as a snapshot of the checkout_items at order confirmation time.
 */
class OrderItem extends Model
{
    protected $table = 'order_items';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_id',
        'product_id',
        'variant_id',
        'qty',
        'price',
        'subtotal',
        'available_stock',
        'product_slug',
        'product_title',
        'product_description',
        'product_sku',
        'category_id',
        'tax_amount',
        'shop_id',
        'shop_order_id',
        'discount_amount',
        'image_url',
        'currency',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }
}