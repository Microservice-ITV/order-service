<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'product_id', 'qty', 'price'];

    protected $casts = [
        'product_id' => 'integer',
        'qty' => 'integer',
        'price' => 'decimal:2',
    ];

    /**
     * Get the parent order
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Calculate item subtotal
     */
    public function getSubtotalAttribute(): float
    {
        return (float) ($this->price * $this->qty);
    }
}

