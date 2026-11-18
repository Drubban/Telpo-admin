<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // ICCID: el numero de la tarjeta SIM (19-20 digitos), con el que el operador identifica la linea.
            $table->string('sim_iccid', 24)->nullable()->after('phone_source');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('sim_iccid');
        });
    }
};
