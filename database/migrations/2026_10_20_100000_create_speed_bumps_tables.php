<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rutas: solo sirven para organizar y filtrar. TODAS las Telpo reciben TODOS los topes activos.
        Schema::create('bump_routes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->timestamps();
        });

        Schema::create('speed_bumps', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->double('latitude');
            $table->double('longitude');
            // Distancia fija de aviso en metros; null = se calcula segun la velocidad.
            $table->unsignedSmallInteger('radius_m')->nullable();
            // Sentido al que aplica (0 = norte, 90 = este); null = ambos sentidos.
            $table->unsignedSmallInteger('heading_deg')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('bump_route_speed_bump', function (Blueprint $table) {
            $table->foreignId('speed_bump_id')->constrained('speed_bumps')->cascadeOnDelete();
            $table->foreignId('bump_route_id')->constrained('bump_routes')->cascadeOnDelete();
            $table->primary(['speed_bump_id', 'bump_route_id']);
        });

        // Rangos validos tambien a nivel de base de datos (solo PostgreSQL; SQLite no admite ALTER ... ADD CONSTRAINT).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE speed_bumps ADD CONSTRAINT speed_bumps_ranges CHECK ('
                .'latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180 '
                .'AND (heading_deg IS NULL OR heading_deg BETWEEN 0 AND 359) '
                .'AND (radius_m IS NULL OR radius_m BETWEEN 20 AND 500))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bump_route_speed_bump');
        Schema::dropIfExists('speed_bumps');
        Schema::dropIfExists('bump_routes');
    }
};
