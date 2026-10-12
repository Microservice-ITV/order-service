<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-shop shipping fee for a checkout session (for payment total + future wallet / settlement).
 */
class CheckoutShopShipping extends Model
{
    protected $table = 'checkout_shop_shipping';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'checkout_id',
        'shop_id',
        'shipping_fee',
        'delivery_company_id',
    ];

    protected $casts = [
        'shipping_fee' => 'decimal:2',
    ];

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_id');
    }
}
