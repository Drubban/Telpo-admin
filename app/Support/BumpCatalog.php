<?php

namespace App\Support;

use App\Models\SpeedBump;

/**
 * Lista de topes que reciben las Telpo. Todas reciben todos los topes activos: un tope es un lugar
 * fisico, sin importar la ruta. La "version" cambia con cualquier dato que afecte a la app, asi la
 * Telpo solo descarga la lista cuando algo cambio (unos pocos KB, casi sin datos moviles).
 */
final class BumpCatalog
{
    /** @return array{version: string, items: list<array<string, mixed>>} */
    public static function snapshot(): array
    {
        $items = SpeedBump::query()
            ->where('active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'latitude', 'longitude', 'radius_m', 'heading_deg'])
            ->map(fn (SpeedBump $b) => [
                'id' => (int) $b->id,
                'name' => (string) $b->name,
                'lat' => round((float) $b->latitude, 6),
                'lng' => round((float) $b->longitude, 6),
                'radius_m' => $b->radius_m,
                'heading_deg' => $b->heading_deg,
            ])
            ->all();

        return ['version' => substr(sha1((string) json_encode($items)), 0, 16), 'items' => $items];
    }

    public static function version(): string
    {
        return self::snapshot()['version'];
    }
}
