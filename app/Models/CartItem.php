<?php

namespace App\Models;

use App\Classes\Price;
use App\Observers\CartItemObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

#[ObservedBy(CartItemObserver::class)]
class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'plan_id',
        'config_options',
        'checkout_config',
        'quantity',
    ];

    protected $casts = [
        'config_options' => 'array',
        'checkout_config' => 'array',
    ];

    // Set default loads

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function price(): Attribute
    {
        return Attribute::make(
            get: function () {
                $total = 0;
                $setup_fee = 0;

                $total += $this->plan->price()->price;
                $setup_fee += $this->plan->price()->setup_fee;

                $this->product->configOptions->each(function ($option) use (&$total, &$setup_fee) {
                    $selected = (object) collect($this->config_options)->firstWhere('option_id', $option->id);

                    // Checkbox option handling
                    if ($option->type === 'checkbox' && $selected?->value) {
                        $childPrice = $option->children->first()?->price(
                            billing_period: $this->plan->billing_period,
                            billing_unit: $this->plan->billing_unit
                        );

                        $total += $childPrice?->price ?? 0;
                        $setup_fee += $childPrice?->setup_fee ?? 0;

                        return;
                    }

                    // Skip types without prices
                    if (in_array($option->type, ['text', 'number', 'checkbox'])) {
                        return;
                    }

                    if (!$selected || !isset($selected->value)) {
                        return;
                    }

                    $child = $option->children->where('id', $selected->value)->first()?->price(
                        billing_period: $this->plan->billing_period,
                        billing_unit: $this->plan->billing_unit
                    );

                    $total += $child?->price ?? 0;
                    $setup_fee += $child?->setup_fee ?? 0;
                });

                $price = new Price([
                    'price' => $total,
                    'currency' => $this->plan->price()->currency,
                    'setup_fee' => $setup_fee,
                ], apply_exclusive_tax: true);

                if ($this->isCouponApplicable()) {
                    $coupon = $this->cart->coupon;
                    $user = Auth::user();

                    $productDiscount = $coupon->calculateDiscount($price->price, $user, $price->currency);
                    $setupDiscount = $coupon->calculateDiscount($price->setup_fee, $user, $price->currency, 'setup_fee');

                    $price->price -= $productDiscount;
                    $price->setup_fee -= $setupDiscount;
                    $price->setDiscount($productDiscount + $setupDiscount);
                }

                return $price;
            }
        );
    }

    public function isCouponApplicable(): bool
    {
        if (!$this->cart?->coupon_id || !$this->cart?->coupon) {
            return false;
        }

        $coupon = $this->cart->coupon;

        // Fetch eligible product IDs
        $eligibleProductIds = $coupon->products()->pluck('products.id')->all();

        // Current product not eligible
        if (!empty($eligibleProductIds) && !in_array($this->product->id, $eligibleProductIds)) {
            return false;
        }

        // Apply-once logic
        if ($coupon->apply_once_only) {
            $eligibleItems = $this->cart->items()
                ->get()
                ->filter(fn ($item) => empty($eligibleProductIds) || in_array($item->product->id, $eligibleProductIds));

            $firstEligibleItem = $eligibleItems->first();
            if (!$firstEligibleItem || $firstEligibleItem->id !== $this->id) {
                return false;
            }
        }

        return true;
    }
}
