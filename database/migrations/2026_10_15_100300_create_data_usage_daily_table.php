<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una fila por equipo, periodo y dia. Los bytes son ACUMULADOS del periodo al cierre de ese
        // dia (el equipo reporta el total del periodo). El consumo del dia es la diferencia con el
        // dia anterior; el total del periodo es el maximo.
        Schema::create('data_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('day');
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->timestamp('reported_at');

            $table->unique(['device_id', 'period_start', 'day']);
            $table->index(['period_start', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_usage_daily');
    }
};
