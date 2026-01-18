<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $fillable = ['user_id'];

    protected $casts = [
        'user_id' => 'int',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Explicit calculation (no hidden magic)
     */
    public function subtotal(): float
    {
        return round(
            $this->items->sum(fn(CartItem $item) => $item->subtotal()),
            2
        );
    }
}
