<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OrderStatus represents a status in the statuses lookup table.
 */
class OrderStatus extends Model
{
    protected $table = 'statuses';
    
    public $timestamps = false;
    
    protected $fillable = ['id', 'name'];
    
    protected $casts = [
        'id' => 'integer',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'order_status');
    }
}
