<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('currency_rate_logs', function (Blueprint $table) {
            $table->id();
            $table->string('base', 8)->index();
            $table->unsignedInteger('count')->default(0);
            $table->text('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rate_logs');
    }
};
