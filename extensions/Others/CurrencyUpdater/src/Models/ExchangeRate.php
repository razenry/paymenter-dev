<?php

namespace Paymenter\Extensions\Others\CurrencyUpdater\src\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = [
        'code',
        'rate',
        'enabled',
        'is_default',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'enabled' => 'boolean',
        'is_default' => 'boolean',
    ];
}
