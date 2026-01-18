<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'price_snapshot',
    ];

    protected $casts = [
        'quantity' => 'int',
        'price_snapshot' => 'float',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Domain calculation (explicit)
     */
    public function subtotal(): float
    {
        return round($this->quantity * $this->price_snapshot, 2);
    }
}
