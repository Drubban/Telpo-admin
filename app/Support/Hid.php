<?php

namespace App\Support;

use Hashids\Hashids;

/**
 * IDs de ruta hasheados. Cada tipo de recurso usa su propia sal ("scope"), asi que el hash de un
 * equipo no sirve como hash de un evento. Ofuscar IDs no sustituye a la autorizacion: todas las
 * rutas de administracion siguen detras de login.
 */
final class Hid
{
    private const ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @var array<string, Hashids> */
    private static array $cache = [];

    private static function make(string $scope): Hashids
    {
        return self::$cache[$scope] ??= new Hashids(
            (string) config('telpo.hashids_salt').'|'.$scope,
            (int) config('telpo.hashids_min_length', 12),
            self::ALPHABET
        );
    }

    public static function encode(string $scope, int $id): string
    {
        return self::make($scope)->encode($id);
    }

    public static function decode(string $scope, ?string $hash): ?int
    {
        if ($hash === null || ! preg_match('/^[A-Za-z0-9]{6,40}$/', $hash)) {
            return null;
        }

        $decoded = self::make($scope)->decode($hash);
        if (count($decoded) !== 1) {
            return null;
        }

        $id = (int) $decoded[0];

        // Solo se acepta la forma canonica: evita que varios textos distintos apunten al mismo ID.
        return self::make($scope)->encode($id) === $hash ? $id : null;
    }
}
