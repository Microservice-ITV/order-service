<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CodCollectionBatch extends Model
{
    use HasUuids;

    protected $table = 'cod_collection_batches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'selection_hash',
        'shop_id',
        'delivery_company_id',
        'effective_at',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(CodCollectionBatchOrder::class, 'batch_id');
    }
}
