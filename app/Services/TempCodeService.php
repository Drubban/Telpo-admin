<?php

namespace App\Services;

use App\Enums\CredentialKind;
use App\Models\Device;
use Carbon\CarbonImmutable;

/**
 * Contrasenas temporales de 15 minutos para Configuracion y Reiniciar/Apagar.
 *
 * La Telpo debe poder validarlas SIN conexion, asi que no se guardan en ningun lado: el codigo es
 * HMAC-SHA256(llave del equipo, "tp1|tipo|minuto") reducido a 8 digitos. La web y la app calculan
 * exactamente lo mismo. La app acepta el codigo solo si el minuto con que se genero esta dentro de
 * los ultimos 15 minutos; despues deja de servir. El codigo es de un tipo y de un equipo: el de
 * "reiniciar/apagar" no abre Configuracion y el de una unidad no sirve en otra.
 */
class TempCodeService
{
    public const VALID_MINUTES = 15;
    public const DIGITS = 8;

    /** @return list<CredentialKind> Contrasenas que admiten codigo temporal (la de administracion no). */
    public static function kinds(): array
    {
        return [CredentialKind::Settings, CredentialKind::Power];
    }

    public static function allows(CredentialKind $kind): bool
    {
        return in_array($kind, self::kinds(), true);
    }

    /** Codigo para un minuto dado (minutos desde epoch UTC). Debe coincidir con la app Android. */
    public function codeFor(string $secretBase64, string $kind, int $minute): string
    {
        $secret = base64_decode($secretBase64, true);
        if ($secret === false || $secret === '') {
            throw new \InvalidArgumentException('Llave temporal invalida');
        }

        $mac = hash_hmac('sha256', "tp1|{$kind}|{$minute}", $secret, true);
        $number = unpack('N', substr($mac, 0, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Crea la llave del equipo si aun no tiene (version 1). */
    public function ensureSecret(Device $device): void
    {
        if ($device->temp_secret !== null && $device->temp_secret_version > 0) {
            return;
        }
        $device->temp_secret = base64_encode(random_bytes(32));
        $device->temp_secret_version = max(1, (int) $device->temp_secret_version + 1);
        $device->save();
    }

    /**
     * Llave que viaja al equipo en la sincronizacion (solo si no tiene la version actual).
     *
     * @return array{version: int, secret: string}|null
     */
    public function payloadFor(Device $device, int $reportedVersion): ?array
    {
        $this->ensureSecret($device);
        if ($reportedVersion >= (int) $device->temp_secret_version) {
            return null;
        }

        return ['version' => (int) $device->temp_secret_version, 'secret' => (string) $device->temp_secret];
    }

    /**
     * @return array{code: string, issued_at: CarbonImmutable, expires_at: CarbonImmutable, device_ready: bool}
     */
    public function issue(Device $device, CredentialKind $kind, ?CarbonImmutable $now = null): array
    {
        if (! self::allows($kind)) {
            throw new \InvalidArgumentException('Ese tipo no admite codigo temporal');
        }

        $this->ensureSecret($device);
        $now ??= CarbonImmutable::now('UTC');
        $minute = intdiv($now->getTimestamp(), 60);
        $issuedAt = CarbonImmutable::createFromTimestampUTC($minute * 60);

        return [
            'code' => $this->codeFor((string) $device->temp_secret, $kind->value, $minute),
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->addMinutes(self::VALID_MINUTES),
            // El equipo ya recibio la llave actual: si no, el codigo no le servira hasta sincronizar.
            'device_ready' => (int) $device->temp_secret_acked_version >= (int) $device->temp_secret_version,
        ];
    }
}
