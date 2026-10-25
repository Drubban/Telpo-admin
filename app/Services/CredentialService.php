<?php

namespace App\Services;

use App\Enums\CredentialKind;
use App\Models\DeviceCredential;
use App\Models\User;

/**
 * Las contrasenas viajan a los equipos como sal + hash PBKDF2-HMAC-SHA256, nunca en claro. Asi la
 * Telpo puede verificarlas sin conexion. La app Android calcula exactamente el mismo PBKDF2.
 */
class CredentialService
{
    /** @return array{salt: string, hash: string, iterations: int} */
    public function derive(string $plain, ?string $saltBase64 = null, ?int $iterations = null): array
    {
        $salt = $saltBase64 !== null ? base64_decode($saltBase64, true) : random_bytes(16);
        $iterations ??= (int) config('telpo.pbkdf2_iterations', 120000);

        $raw = hash_pbkdf2('sha256', $plain, $salt, $iterations, 32, true);

        return [
            'salt' => base64_encode($salt),
            'hash' => base64_encode($raw),
            'iterations' => $iterations,
        ];
    }

    public function matches(string $plain, DeviceCredential $credential): bool
    {
        $derived = $this->derive($plain, $credential->salt, $credential->iterations);

        return hash_equals($credential->hash, $derived['hash']);
    }

    public function set(CredentialKind $kind, string $plain, User $by): DeviceCredential
    {
        $derived = $this->derive($plain);

        $credential = DeviceCredential::firstOrNew(['kind' => $kind->value]);
        $credential->fill($derived);
        $credential->version = ($credential->exists ? $credential->version : 0) + 1;
        $credential->updated_by = $by->id;
        $credential->save();

        return $credential;
    }

    /**
     * True si la clave en claro coincide con alguna de las OTRAS contrasenas.
     * Se usa para exigir que las tres sean distintas.
     */
    public function collidesWithOther(CredentialKind $kind, string $plain): bool
    {
        return DeviceCredential::where('kind', '!=', $kind->value)
            ->get()
            ->contains(fn (DeviceCredential $other) => $this->matches($plain, $other));
    }

    /**
     * Credenciales que el equipo aun no tiene o tiene desactualizadas.
     *
     * @param  array<string, int>  $deviceVersions  version que ya tiene el equipo por tipo
     * @return array<string, array{salt: string, hash: string, iterations: int, version: int}>
     */
    public function payloadFor(array $deviceVersions = []): array
    {
        $payload = [];
        foreach (DeviceCredential::all() as $credential) {
            if ((int) ($deviceVersions[$credential->kind] ?? 0) >= $credential->version) {
                continue;
            }
            $payload[$credential->kind] = [
                'salt' => $credential->salt,
                'hash' => $credential->hash,
                'iterations' => (int) $credential->iterations,
                'version' => (int) $credential->version,
            ];
        }

        return $payload;
    }

    /** @return list<string> Tipos de contrasena que aun no se han definido. */
    public function missingKinds(): array
    {
        $defined = DeviceCredential::pluck('kind')->all();

        return array_values(array_diff(CredentialKind::values(), $defined));
    }
}
