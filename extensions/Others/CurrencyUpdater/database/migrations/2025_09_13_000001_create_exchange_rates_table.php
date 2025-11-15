<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->index();
            $table->decimal('rate', 18, 8)->default(1);
            $table->boolean('enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
