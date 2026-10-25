<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            // Identificador que genera la app al instalarse; sobrevive si no se puede leer el IMEI.
            $table->uuid('install_id')->unique();
            $table->string('imei', 20)->nullable()->unique();
            $table->string('unit_number', 40)->nullable()->index();
            $table->string('label', 80)->nullable();
            $table->string('serial', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('status', 16)->default('active')->index(); // active | inactive | released
            $table->char('token_hash', 64)->unique();
            $table->string('app_version', 32)->nullable();
            $table->boolean('nfc_enabled')->nullable();
            $table->boolean('camera_ok')->nullable();
            $table->boolean('is_device_owner')->nullable();
            $table->timestamp('unit_updated_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('last_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
