<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodCollectionBatchOrder extends Model
{
    protected $table = 'cod_collection_batch_orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'batch_id',
        'order_id',
        'shop_id',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CodCollectionBatch::class, 'batch_id');
    }
}
