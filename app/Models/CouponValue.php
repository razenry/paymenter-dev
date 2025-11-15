<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class CouponValue extends Model implements AuditableContract
{
    use Auditable, HasFactory;

    protected $table = 'coupon_values';

    protected $fillable = [
        'coupon_id',
        'value',
        'currency',
    ];

    protected $casts = [
        'coupon_id' => 'integer',
        'value' => 'float',
        'currency' => 'string',
    ];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
