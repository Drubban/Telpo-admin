<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Lo que reporta cada Telpo sobre el aviso de topes.
            $table->string('bumps_version', 40)->nullable();
            $table->unsignedInteger('bump_count')->nullable();
            $table->unsignedInteger('bump_alerts_today')->nullable();
            $table->string('gps_status', 20)->nullable(); // ok | gps_off | no_permission | no_fix | no_bumps | not_running
            $table->timestamp('bump_stats_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['bumps_version', 'bump_count', 'bump_alerts_today', 'gps_status', 'bump_stats_at']);
        });
    }
};
