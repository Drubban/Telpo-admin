<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Version de la app de cobro instalada en cada Telpo (la reporta la app en cada sync).
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('billing_app_installed')->nullable();
            $table->string('billing_app_version', 40)->nullable();
            $table->unsignedBigInteger('billing_app_version_code')->nullable();
            $table->timestamp('billing_app_checked_at')->nullable();
        });

        // Ajustes sencillos clave/valor (version objetivo de la app de cobro).
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Una alerta por equipo, periodo y umbral: nunca se repite aunque el equipo sincronice mil veces.
        Schema::create('usage_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedSmallInteger('threshold');
            $table->unsignedBigInteger('total_bytes');
            $table->unsignedBigInteger('limit_bytes');
            $table->boolean('silent')->default(false); // umbral superado de golpe junto con otro mayor: solo se registra
            $table->string('email_status', 10)->default('pending');    // pending | sent | failed | skipped
            $table->string('whatsapp_status', 10)->default('pending'); // pending | sent | failed | skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('notify_error')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'period_start', 'threshold']);
            $table->index(['period_start', 'threshold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_alerts');
        Schema::dropIfExists('app_settings');
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['billing_app_installed', 'billing_app_version', 'billing_app_version_code', 'billing_app_checked_at']);
        });
    }
};
