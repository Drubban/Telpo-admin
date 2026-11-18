<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Número de la línea (SIM) y de dónde salió: "sim" (lo leyó la Telpo) o "manual" (capturado en la web).
            $table->string('phone_number', 24)->nullable();
            $table->string('phone_source', 10)->nullable();
            // Consumo del periodo por aplicación, tal como lo reportó la Telpo: {period_start_ms, apps:[...]}.
            $table->json('usage_breakdown')->nullable();
            $table->timestamp('usage_breakdown_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'phone_source', 'usage_breakdown', 'usage_breakdown_at']);
        });
    }
};
