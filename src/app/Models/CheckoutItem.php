<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CheckoutItem represents an item in a checkout session (formerly "order_items" table).
 */
class CheckoutItem extends Model
{
    protected $table = 'checkout_items';

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;
    
    protected $fillable = [
        'id',
        'checkout_id',
        'product_id',
        'variant_id',
        'qty',
        'price',
        'line_total',
        'available_stock',
        'product_slug',
        'product_title',
        'product_description',
        'product_sku',
        'category_id',
        'tax_rate',
        'tax_amount',
        'shop_id',
        'image_url',
        'currency',
    ];

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_id');
    }
}
