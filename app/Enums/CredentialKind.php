<?php

namespace App\Enums;

/** Las tres contrasenas distintas que se manejan desde la web. */
enum CredentialKind: string
{
    case Settings = 'settings';
    case Power = 'power';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Settings => 'Configuración del equipo',
            self::Power => 'Reiniciar y apagar',
            self::Admin => 'Salir o quitar launcher y configuración de la app',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
