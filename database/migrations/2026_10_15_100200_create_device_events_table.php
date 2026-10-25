<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            // Lo genera la Telpo; el unique vuelve idempotente el reenvio de eventos.
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->boolean('success')->nullable(); // null = evento informativo
            $table->json('detail')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');

            $table->index(['device_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
    }
};
