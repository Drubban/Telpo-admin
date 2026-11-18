<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Ultimo semaforo de red reportado: 0 = verde, 1 = amarillo, 2 = rojo.
            $table->unsignedTinyInteger('signal_mobile')->nullable();
            $table->unsignedTinyInteger('signal_wifi')->nullable();
            $table->string('signal_mobile_reason', 16)->nullable();
            $table->string('signal_wifi_reason', 16)->nullable();
            $table->timestamp('signal_checked_at')->nullable();

            // Llave por equipo para las contrasenas temporales (cifrada con APP_KEY al guardarse).
            $table->text('temp_secret')->nullable();
            $table->unsignedInteger('temp_secret_version')->default(0);
            $table->unsignedInteger('temp_secret_acked_version')->default(0);
        });

        Schema::create('device_signal_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->unsignedTinyInteger('mobile');
            $table->unsignedTinyInteger('wifi');
            $table->unsignedInteger('mobile_rtt_ms')->nullable();
            $table->unsignedInteger('wifi_rtt_ms')->nullable();
            $table->string('mobile_reason', 16)->nullable();
            $table->string('wifi_reason', 16)->nullable();

            // Reenviar el mismo lote no duplica muestras.
            $table->unique(['device_id', 'sampled_at']);
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_signal_samples');
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'signal_mobile', 'signal_wifi', 'signal_mobile_reason', 'signal_wifi_reason', 'signal_checked_at',
                'temp_secret', 'temp_secret_version', 'temp_secret_acked_version',
            ]);
        });
    }
};
