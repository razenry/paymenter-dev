<?php

use App\Models\Coupon;
use App\Models\CouponValue;
use App\Models\Currency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create coupon_values table
        Schema::create('coupon_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->decimal('value', 10, 2);
            $table->string('currency', 10);
            $table->timestamps();

            $table->foreign('coupon_id')
                ->references('id')
                ->on('coupons')
                ->cascadeOnDelete();
        });

        // Auto-create values for existing coupons
        $coupons = Coupon::where('type', 'fixed')->get();
        $currencies = Currency::all()->pluck('code');

        foreach ($coupons as $coupon) {
            foreach ($currencies as $currency) {
                CouponValue::create([
                    'coupon_id' => $coupon->id,
                    'value'     => $coupon->value ?? 0, // use existing value
                    'currency'  => $currency,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_values');
    }
};
