<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vistas de solo lectura pensadas para Power BI Desktop (conector PostgreSQL). Son opcionales: el
 * dashboard de la web no depende de ellas. Solo se crean en PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW bi_consumo_diario AS
            SELECT d.imei,
                   d.unit_number AS unidad,
                   u.period_start AS inicio_periodo,
                   u.day AS dia,
                   u.total_bytes AS acumulado_bytes,
                   GREATEST(u.total_bytes - COALESCE(LAG(u.total_bytes) OVER (
                       PARTITION BY u.device_id, u.period_start ORDER BY u.day), 0), 0) AS consumo_dia_bytes
            FROM data_usage_daily u
            JOIN devices d ON d.id = u.device_id
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW bi_eventos AS
            SELECT e.uuid,
                   d.imei,
                   d.unit_number AS unidad,
                   e.type AS tipo,
                   e.success AS exitoso,
                   e.occurred_at AS fecha_utc,
                   e.detail ->> 'reason' AS motivo
            FROM device_events e
            JOIN devices d ON d.id = e.device_id
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS bi_eventos');
        DB::statement('DROP VIEW IF EXISTS bi_consumo_diario');
    }
};
