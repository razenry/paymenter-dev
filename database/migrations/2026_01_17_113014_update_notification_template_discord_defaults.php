<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing email_verification and password_reset templates to force disable Discord notifications
        DB::table('notification_templates')
            ->whereIn('key', ['email_verification', 'password_reset'])
            ->update(['discord_enabled' => 'never']);

        // Change the default value for discord_enabled from 'choice_on' to 'choice_off'
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->enum('discord_enabled', ['force', 'choice_on', 'choice_off', 'never'])
                ->default('choice_off')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert email_verification and password_reset templates back to choice_on
        DB::table('notification_templates')
            ->whereIn('key', ['email_verification', 'password_reset'])
            ->update(['discord_enabled' => 'choice_on']);

        // Revert the default value back to 'choice_on'
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->enum('discord_enabled', ['force', 'choice_on', 'choice_off', 'never'])
                ->default('choice_on')
                ->change();
        });
    }
};
