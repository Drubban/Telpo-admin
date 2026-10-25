<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una fila por tipo de contrasena. Solo se guarda sal + hash PBKDF2, nunca la clave en claro.
        Schema::create('device_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16)->unique(); // settings | power | admin
            $table->string('salt', 64);
            $table->string('hash', 96);
            $table->unsignedInteger('iterations');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_credentials');
    }
};
