<?php

namespace App\Support;

/**
 * Lee coordenadas pegadas como texto, una por linea, por ejemplo:
 *
 *   19.824410, -99.112018<TAB>Crucero Bocanegra
 *   19.828970 -99.096018 Villas
 *
 * Acepta separadores coma, punto y coma, tabulador o espacios, y el nombre es opcional. Solo se
 * admite punto decimal. Una primera linea de titulos ("coordenada  ubicacion") se omite sola.
 */
final class BumpImporter
{
    public const MAX_ROWS = 500;

    private const PATTERN = '/^(-?\d{1,3}(?:\.\d+)?)\s*[,;\t ]\s*(-?\d{1,3}(?:\.\d+)?)\s*(?:[,;|\t]+\s*|\s+[-\x{2013}\x{2014}]\s*|\s+)?(.*)$/u';

    /**
     * @return array{rows: list<array{line:int,name:string,latitude:float,longitude:float}>, errors: list<array{line:int,text:string,reason:string}>}
     */
    public static function parse(string $text): array
    {
        $rows = [];
        $errors = [];
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $index => $raw) {
            $line = $index + 1;
            $clean = trim((string) $raw);
            if ($clean === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = ['line' => $line, 'text' => self::short($clean), 'reason' => 'Se alcanzo el maximo de '.self::MAX_ROWS.' topes por importacion.'];
                break;
            }

            if (! preg_match(self::PATTERN, $clean, $m)) {
                // Una primera linea de titulos no es un error.
                if ($rows === [] && $errors === [] && preg_match('/coord|lat|ubic|nombre/iu', $clean)) {
                    continue;
                }
                $errors[] = ['line' => $line, 'text' => self::short($clean), 'reason' => 'No se encontraron latitud y longitud.'];

                continue;
            }

            $lat = (float) $m[1];
            $lng = (float) $m[2];
            $name = self::cleanName((string) $m[3]);

            if (! Geo::inBounds($lat, $lng)) {
                $reason = Geo::inBounds($lng, $lat)
                    ? 'Latitud y longitud parecen invertidas.'
                    : 'Las coordenadas quedan fuera de la zona de operacion.';
                $errors[] = ['line' => $line, 'text' => self::short($clean), 'reason' => $reason];

                continue;
            }

            $rows[] = [
                'line' => $line,
                'name' => $name !== '' ? $name : 'Tope '.(count($rows) + 1),
                'latitude' => round($lat, 6),
                'longitude' => round($lng, 6),
            ];
        }

        return ['rows' => self::numberRepeatedNames($rows), 'errors' => $errors];
    }

    /** Si un nombre se repite ("Rinconada" x3) se numera ("Rinconada 1", "Rinconada 2"...) para distinguirlos. */
    private static function numberRepeatedNames(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = mb_strtolower($row['name']);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $seen = [];
        foreach ($rows as &$row) {
            $key = mb_strtolower($row['name']);
            if ($counts[$key] > 1) {
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $row['name'] = mb_substr($row['name'], 0, 74).' '.$seen[$key];
            }
        }
        unset($row);

        return $rows;
    }

    /** Deja solo caracteres seguros para un nombre y lo recorta a 80. */
    private static function cleanName(string $name): string
    {
        $name = preg_replace('/[^\pL\pN _.,\-\/#()&\x27\x{00B0}+]/u', '', trim($name)) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';

        return mb_substr(trim($name), 0, 80);
    }

    private static function short(string $text): string
    {
        return mb_substr($text, 0, 80);
    }
}
