<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class Coupon extends Model implements Auditable
{
    use \App\Models\Traits\Auditable, HasFactory;

    protected $fillable = [
        'type',
        'time',
        'code',
        'value',
        'max_uses',
        'max_uses_per_user',
        'starts_at',
        'expires_at',
        'recurring',
        'allowed_roles',
        'new_users_only',
        'existing_users_only',
        'apply_once_only',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'max_uses_per_user' => 'integer',
        'value' => 'float',
        'allowed_roles' => 'array',
        'new_users_only' => 'boolean',
        'existing_users_only' => 'boolean',
        'apply_once_only' => 'boolean',
    ];

    /**
     * Get the products that belong to the option.
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'coupon_products');
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Check if the user has exceeded the maximum allowed uses of this coupon
     *
     * @param  int  $userId
     */
    public function hasExceededMaxUsesPerUser($userId): bool
    {
        if (empty($this->max_uses_per_user)) {
            return false;
        }

        return $this->services()
            ->where('user_id', $userId)
            ->count() >= $this->max_uses_per_user;
    }

    public function calculateDiscount($price, mixed $user, Currency $currency, $type = 'price')
    {
        if (!in_array($type, ['price', 'setup_fee'])) {
            throw new \InvalidArgumentException('Invalid type for coupon discount calculation');
        }

        if (!in_array($this->applies_to, ['all', $type])) {
            return 0;
        }

        if (!$user instanceof User) {
            return 0;
        }

        if (!self::isAllowedForRole($user->role->id)) {
            return 0;
        }

        $orderCount = $user->orders()->count();
        if ($this->new_users_only && $orderCount > 0) {
            return 0;
        }

        if ($this->existing_users_only && $orderCount < 1) {
            return 0;
        }

        // Calculate discount
        if ($this->type === 'percentage') {
            $discount = ($price * $this->value) / 100;
        } else { // fixed
            $voucherValue = $this->couponValues()
                ->where('currency', $currency->code)
                ->first();

            if (!$voucherValue) {
                return 0;
            }

            $discount = $voucherValue->value;
        }

        // Prevent discount exceeding price
        return min($discount, $price);
    }

    /**
     * Coupon values relationship
     */
    public function couponValues()
    {
        return $this->hasMany(CouponValue::class, 'coupon_id');
    }

    public function isAllowedForRole($roleId): bool
    {
        if (!$this->allowed_roles || !is_array($this->allowed_roles)) {
            return true; // If no restriction → allow all roles
        }

        return in_array($roleId, $this->allowed_roles);
    }
}
