<?php

namespace App\Enums;

/** Tipos de evento que reportan las Telpo. Cualquier otro valor se rechaza en la sincronizacion. */
enum EventType: string
{
    case SettingsAccess = 'settings_access';
    case PowerAccess = 'power_access';
    case AdminAccess = 'admin_access';
    case LauncherSwitch = 'launcher_switch';
    case LauncherRelease = 'launcher_release';
    case AppConfigChange = 'app_config_change';
    case PowerAction = 'power_action';
    case DeviceCheck = 'device_check';
    case DataUsage = 'data_usage';
    case SyncError = 'sync_error';
    case BumpAlert = 'bump_alert';

    public function label(): string
    {
        return match ($this) {
            self::SettingsAccess => 'Acceso a Configuración',
            self::PowerAccess => 'Acceso a Reiniciar/Apagar',
            self::AdminAccess => 'Acceso de administración',
            self::LauncherSwitch => 'Cambio de launcher',
            self::LauncherRelease => 'Liberar/quitar launcher',
            self::AppConfigChange => 'Configuración de la app',
            self::PowerAction => 'Reinicio/apagado',
            self::DeviceCheck => 'Revisión NFC/QR',
            self::DataUsage => 'Reporte de consumo',
            self::SyncError => 'Error de sincronización',
            self::BumpAlert => 'Aviso de tope',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Eventos que representan un intento de acceso (exitoso o fallido). */
    public static function attemptValues(): array
    {
        return [
            self::SettingsAccess->value,
            self::PowerAccess->value,
            self::AdminAccess->value,
            self::LauncherSwitch->value,
            self::LauncherRelease->value,
        ];
    }
}
