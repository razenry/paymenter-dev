<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('currency_rate_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('base', 3)->index();
            $table->string('currency', 3)->index();
            $table->decimal('rate', 18, 8);
            $table->string('provider')->index();
            $table->timestamp('fetched_at')->index();
            $table->timestamps();

            $table->unique(['base', 'currency', 'fetched_at', 'provider'], 'crl_unique_per_run');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rate_logs');
    }
};
