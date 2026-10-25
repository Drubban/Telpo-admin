<?php

namespace App\Services;

use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EnrollmentService
{
    public function __construct(private readonly CredentialService $credentials) {}

    /**
     * Da de alta (o vuelve a dar de alta) un equipo y devuelve su token en claro, que solo se
     * entrega una vez. Si la app se reinstala, el equipo se reconoce por IMEI o por install_id y
     * conserva su historial; el token anterior deja de funcionar.
     *
     * @param  array<string, mixed>  $data
     * @return array{device: Device, token: string}
     */
    public function enroll(array $data, ?string $ip): array
    {
        return DB::transaction(function () use ($data, $ip) {
            $now = CarbonImmutable::now('UTC');

            $device = Device::where('install_id', $data['install_id'])->first();
            if ($device === null && ! empty($data['imei'])) {
                $device = Device::where('imei', $data['imei'])->first();
            }
            $device ??= new Device;

            $token = bin2hex(random_bytes(32));

            $device->install_id = $data['install_id'];
            $device->token_hash = hash('sha256', $token);
            $device->fill(array_filter([
                'app_version' => $data['app_version'] ?? null,
                'model' => $data['model'] ?? null,
                'serial' => $data['serial'] ?? null,
            ], fn ($value) => $value !== null));

            if (! empty($data['imei']) && ! Device::where('imei', $data['imei'])->where('id', '!=', $device->id ?? 0)->exists()) {
                $device->imei = $data['imei'];
            }
            if (! empty($data['unit_number']) && $device->unit_number === null) {
                $device->unit_number = $data['unit_number'];
                $device->unit_updated_at = $now;
            }

            // Un equipo desactivado desde la web no puede volver a darse de alta solo.
            if ($device->exists && $device->status === 'inactive') {
                abort(403, 'Equipo desactivado');
            }
            $device->status = 'active';
            $device->enrolled_at = $now;
            $device->last_seen_at = $now;
            $device->last_ip = $ip;
            $device->save();

            return ['device' => $device, 'token' => $token];
        });
    }
}
