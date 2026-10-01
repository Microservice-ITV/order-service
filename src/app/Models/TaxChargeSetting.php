<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxChargeSetting extends Model
{
    protected $table = 'tax_charge_settings';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'is_enabled',
        'currency',
        'updated_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];
}
