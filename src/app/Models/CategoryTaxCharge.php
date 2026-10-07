<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryTaxCharge extends Model
{
    protected $table = 'category_tax_charges';

    protected $primaryKey = 'category_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'category_id',
        'tax_id',
        'tax_name',
        'rate',
        'is_active',
        'category_name',
        'updated_at',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'is_active' => 'boolean',
    ];
}
