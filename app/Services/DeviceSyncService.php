<?php

namespace App\Services;

use App\Enums\EventType;
use App\Models\DataUsageDaily;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\UsageAlert;
use App\Support\BillingCycle;
use App\Support\BumpCatalog;
use App\Support\Ms;
use App\Support\Signal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Procesa lo que la Telpo envia en cada sincronizacion. Todo es idempotente: reenviar el mismo
 * lote no duplica eventos ni suma consumo dos veces.
 */
class DeviceSyncService
{
    /** Una marca de tiempo del equipo mas alejada que esto del servidor se considera reloj mal puesto. */
    private const MAX_CLOCK_SKEW_DAYS = 3;

    /** @var list<UsageAlert> alertas de consumo nuevas de esta sincronizacion (se avisan al terminar). */
    private array $newAlerts = [];

    public function __construct(
        private readonly CredentialService $credentials,
        private readonly UsageAlertService $alerts,
        private readonly TempCodeService $tempCodes,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  ya validado por SyncRequest
     * @return array<string, mixed> respuesta para la Telpo
     */
    public function handle(Device $device, array $payload, ?string $ip): array
    {
        // Lo importante (datos del equipo, eventos, consumo) se guarda en una transaccion. La
        // respuesta con credenciales y topes se arma DESPUES: si algo falla ahi, el equipo ya no
        // pierde lo que mando ni se queda con eventos pendientes para siempre.
        $this->newAlerts = [];
        $core = DB::transaction(function () use ($device, $payload, $ip) {
            $now = CarbonImmutable::now('UTC');

            $imeiConflict = $this->updateDevice($device, $payload, $now, $ip);
            $acked = $this->storeEvents($device, (array) ($payload['events'] ?? []), $now);
            $usageStatus = isset($payload['usage']) && is_array($payload['usage'])
                ? $this->storeUsage($device, $payload['usage'], $now)
                : 'none';

            $signalAcked = $this->storeSignal($device, (array) ($payload['signal']['samples'] ?? []), $now);

            return [
                'acked_events' => $acked,
                'signal_acked_ms' => $signalAcked,
                'usage' => $usageStatus,
                'unit' => [
                    'number' => $device->unit_number,
                    'updated_at_ms' => Ms::of($device->unit_updated_at),
                ],
                'imei_conflict' => $imeiConflict,
                'cutoff_day' => BillingCycle::cutoffDay(),
                'server_time_ms' => Ms::of($now),
            ];
        });

        // Avisos de consumo (correo/WhatsApp): despues de guardar y sin afectar la respuesta al equipo.
        foreach ($this->newAlerts as $alert) {
            try {
                $this->alerts->notify($alert);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Credenciales: si falla, se omiten en esta vuelta (el equipo las pedira en la siguiente).
        try {
            $core['credentials'] = $this->credentials->payloadFor((array) ($payload['credential_versions'] ?? []));
        } catch (\Throwable $e) {
            report($e);
            $core['credentials'] = [];
        }

        // Llave de las contrasenas temporales: solo viaja si el equipo no tiene la version actual.
        try {
            $secret = $this->tempCodes->payloadFor($device, (int) ($payload['temp_secret_version'] ?? 0));
            if ($secret !== null) {
                $core['temp_secret'] = $secret;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Topes: la lista completa solo viaja si la version del equipo es distinta (unos pocos KB).
        try {
            $bumps = BumpCatalog::snapshot();
            $core['bumps'] = ($payload['bumps_version'] ?? '') === $bumps['version']
                ? ['version' => $bumps['version']]
                : $bumps;
        } catch (\Throwable $e) {
            report($e);
        }

        return $core;
    }

    /** @return bool true si el IMEI enviado ya pertenece a otro equipo (no se aplica). */
    private function updateDevice(Device $device, array $p, CarbonImmutable $now, ?string $ip): bool
    {
        $device->last_seen_at = $now;
        $device->last_ip = $ip;

        foreach (['app_version', 'model', 'serial'] as $field) {
            if (! empty($p[$field])) {
                $device->{$field} = $p[$field];
            }
        }
        foreach (['nfc_enabled', 'camera_ok', 'is_device_owner'] as $flag) {
            if (array_key_exists($flag, $p) && $p[$flag] !== null) {
                $device->{$flag} = (bool) $p[$flag];
            }
        }

        // Numero de la linea: si la Telpo lo pudo leer de la SIM, manda sobre el capturado a mano.
        if (! empty($p['phone_number'])) {
            $device->phone_number = $p['phone_number'];
            $device->phone_source = 'sim';
        }

        // ICCID de la SIM (el numero impreso en la tarjeta): sirve para buscar la linea en la plataforma del operador.
        if (! empty($p['sim_iccid'])) {
            $device->sim_iccid = $p['sim_iccid'];
        }

        // Consumo por aplicacion (solo las que mas gastaron; la Telpo ya manda la lista recortada).
        if (isset($p['usage']['apps']) && is_array($p['usage']['apps'])) {
            $apps = [];
            foreach (array_slice($p['usage']['apps'], 0, 30) as $a) {
                $apps[] = [
                    'package' => (string) ($a['package'] ?? ''),
                    'label' => (string) ($a['label'] ?? ''),
                    'rx_bytes' => (int) ($a['rx_bytes'] ?? 0),
                    'tx_bytes' => (int) ($a['tx_bytes'] ?? 0),
                ];
            }
            $device->usage_breakdown = [
                'period_start_ms' => (int) ($p['usage']['period_start_ms'] ?? 0),
                'apps' => $apps,
            ];
            $device->usage_breakdown_at = $now;
        }

        // La Telpo avisa que version de la llave temporal tiene; asi la web sabe si ya puede usar codigos.
        if (isset($p['temp_secret_version'])) {
            $device->temp_secret_acked_version = min((int) $p['temp_secret_version'], (int) $device->temp_secret_version);
        }

        $imeiConflict = false;
        if (! empty($p['imei']) && $p['imei'] !== $device->imei) {
            $taken = Device::where('imei', $p['imei'])->where('id', '!=', $device->id)->exists();
            if ($taken) {
                $imeiConflict = true;
            } else {
                $device->imei = $p['imei'];
            }
        }

        // App de cobro: version instalada (solo se acepta el paquete que la web controla).
        if (isset($p['billing_app']) && is_array($p['billing_app'])
            && ($p['billing_app']['package'] ?? null) === \App\Support\BillingApp::package()) {
            $b = $p['billing_app'];
            $installed = (bool) ($b['installed'] ?? false);
            $device->billing_app_installed = $installed;
            $device->billing_app_version = $installed ? ($b['version_name'] ?? null) : null;
            $device->billing_app_version_code = $installed && isset($b['version_code']) ? (int) $b['version_code'] : null;
            $device->billing_app_checked_at = $now;
        }

        // Aviso de topes: version de la lista que tiene el equipo y su estado (GPS, avisos del dia).
        if (! empty($p['bumps_version']) && is_string($p['bumps_version'])) {
            $device->bumps_version = $p['bumps_version'];
        }
        if (isset($p['bump_stats']) && is_array($p['bump_stats'])) {
            $stats = $p['bump_stats'];
            $device->bump_count = isset($stats['count']) ? (int) $stats['count'] : $device->bump_count;
            $device->bump_alerts_today = isset($stats['alerts_today']) ? (int) $stats['alerts_today'] : $device->bump_alerts_today;
            $device->gps_status = $stats['gps_status'] ?? $device->gps_status;
            $device->bump_stats_at = $now;
        }

        // Unidad: gana el cambio mas reciente (la web o la app del equipo).
        if (array_key_exists('unit_number', $p) && $p['unit_number'] !== $device->unit_number) {
            $deviceMs = (int) ($p['unit_updated_at_ms'] ?? 0);
            $serverMs = Ms::of($device->unit_updated_at);
            if ($deviceMs > $serverMs) {
                $device->unit_number = $p['unit_number'] ?: null;
                $device->unit_updated_at = CarbonImmutable::createFromTimestampMsUTC(min($deviceMs, Ms::of($now)));
            }
        }

        $device->save();

        return $imeiConflict;
    }

    /**
     * Guarda las muestras del semaforo de red (una cada 10 min) y deja la mas reciente en el equipo.
     *
     * @param  array<int, mixed>  $samples
     * @return int|null marca (ms) de la muestra mas reciente aceptada; la Telpo ya no reenvia hasta ahi
     */
    private function storeSignal(Device $device, array $samples, CarbonImmutable $now): ?int
    {
        $rows = [];
        $latest = null;
        $minMs = Ms::of($now->subDays(self::MAX_CLOCK_SKEW_DAYS));
        $maxMs = Ms::of($now->addHour());

        foreach ($samples as $s) {
            if (! is_array($s) || ! isset($s['at_ms'], $s['mobile'], $s['wifi'])) {
                continue;
            }
            $atMs = (int) $s['at_ms'];
            if ($atMs < $minMs || $atMs > $maxMs) {
                continue;
            }
            $at = CarbonImmutable::createFromTimestampMsUTC($atMs);
            $reason = fn ($v) => in_array($v, Signal::REASONS, true) ? $v : null;
            $rows[$atMs] = [
                'device_id' => $device->id,
                'sampled_at' => $at->format('Y-m-d H:i:s'),
                'mobile' => max(0, min(2, (int) $s['mobile'])),
                'wifi' => max(0, min(2, (int) $s['wifi'])),
                'mobile_rtt_ms' => isset($s['mobile_rtt_ms']) ? (int) $s['mobile_rtt_ms'] : null,
                'wifi_rtt_ms' => isset($s['wifi_rtt_ms']) ? (int) $s['wifi_rtt_ms'] : null,
                'mobile_reason' => $reason($s['mobile_reason'] ?? null),
                'wifi_reason' => $reason($s['wifi_reason'] ?? null),
            ];
            if ($latest === null || $atMs > $latest) {
                $latest = $atMs;
            }
        }

        if ($rows === []) {
            return null;
        }

        DB::table('device_signal_samples')->upsert(
            array_values($rows),
            ['device_id', 'sampled_at'],
            ['mobile', 'wifi', 'mobile_rtt_ms', 'wifi_rtt_ms', 'mobile_reason', 'wifi_reason'],
        );

        $newest = $rows[$latest];
        $checkedAt = CarbonImmutable::createFromTimestampMsUTC($latest);
        if ($device->signal_checked_at === null || $checkedAt->gt($device->signal_checked_at)) {
            $device->forceFill([
                'signal_mobile' => $newest['mobile'],
                'signal_wifi' => $newest['wifi'],
                'signal_mobile_reason' => $newest['mobile_reason'],
                'signal_wifi_reason' => $newest['wifi_reason'],
                'signal_checked_at' => $checkedAt,
            ])->save();
        }

        return $latest;
    }

    /**
     * @param  array<int, mixed>  $events
     * @return list<string> uuids que la Telpo ya puede borrar de su cola (aceptados, duplicados o descartados)
     */
    private function storeEvents(Device $device, array $events, CarbonImmutable $now): array
    {
        $acked = [];
        $rows = [];
        $released = false;

        foreach ($events as $event) {
            if (! is_array($event) || ! isset($event['uuid']) || ! is_string($event['uuid'])) {
                continue;
            }

            $validator = Validator::make($event, [
                'uuid' => ['required', 'uuid'],
                'type' => ['required', 'in:'.implode(',', EventType::values())],
                'success' => ['nullable', 'boolean'],
                'detail' => ['nullable', 'array', 'max:12'],
                'occurred_at_ms' => ['required', 'integer', 'min:1'],
            ]);

            // Un evento invalido se descarta pero se confirma, para no atascar la cola del equipo.
            $acked[] = $event['uuid'];
            if ($validator->fails()) {
                continue;
            }

            // Una liberacion exitosa (la app dejo de ser Device Owner) marca el equipo como liberado.
            if ($event['type'] === EventType::LauncherRelease->value && ($event['success'] ?? null) === true) {
                $released = true;
            }

            $rows[] = [
                'uuid' => $event['uuid'],
                'device_id' => $device->id,
                'type' => $event['type'],
                'success' => array_key_exists('success', $event) && $event['success'] !== null ? (bool) $event['success'] : null,
                'detail' => json_encode($this->cleanDetail($event['detail'] ?? []), JSON_UNESCAPED_UNICODE),
                'occurred_at' => $this->clampTime((int) $event['occurred_at_ms'], $now)->toDateTimeString(),
                'received_at' => $now->toDateTimeString(),
            ];
        }

        if ($rows !== []) {
            // insertOrIgnore sobre el unique(uuid) deja pasar los reenvios sin duplicar.
            DeviceEvent::insertOrIgnore($rows);
        }

        if ($released && $device->status === 'active') {
            $device->status = 'released';
            $device->save();
        }

        return $acked;
    }

    /** Solo claves simples y valores escalares cortos; evita basura y bitacoras gigantes. */
    private function cleanDetail(mixed $detail): array
    {
        if (! is_array($detail)) {
            return [];
        }

        $clean = [];
        foreach ($detail as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9_]{1,40}$/', $key)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$key] = $value;
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, 200);
            }
        }

        return $clean;
    }

    private function clampTime(int $ms, CarbonImmutable $now): CarbonImmutable
    {
        $time = CarbonImmutable::createFromTimestampMsUTC($ms);

        return abs($time->diffInDays($now)) > self::MAX_CLOCK_SKEW_DAYS ? $now : $time;
    }

    /**
     * Guarda el total del periodo en la fila del dia. Devuelve 'stored', 'ignored_period' o
     * 'ignored_value'.
     *
     * @param  array<string, mixed>  $usage  period_start_ms, rx_bytes, tx_bytes, sampled_at_ms
     */
    private function storeUsage(Device $device, array $usage, CarbonImmutable $now): string
    {
        $sampledAt = $this->clampTime((int) ($usage['sampled_at_ms'] ?? Ms::of($now)), $now);
        $cycle = BillingCycle::containing($sampledAt);

        // El periodo del equipo debe coincidir con el del servidor; si el dia de corte difiere
        // (equipo sin sincronizar cambios de configuracion) se ignora hasta que se alinee.
        $deviceStart = CarbonImmutable::createFromTimestampMsUTC((int) ($usage['period_start_ms'] ?? 0))
            ->setTimezone(BillingCycle::timezone());
        if ($deviceStart->toDateString() !== $cycle->key()) {
            return 'ignored_period';
        }

        $rx = (int) ($usage['rx_bytes'] ?? 0);
        $tx = (int) ($usage['tx_bytes'] ?? 0);
        if ($rx < 0 || $tx < 0 || $rx > PHP_INT_MAX / 4 || $tx > PHP_INT_MAX / 4) {
            return 'ignored_value';
        }

        $day = $sampledAt->setTimezone(BillingCycle::timezone())->toDateString();

        $row = DataUsageDaily::firstOrNew([
            'device_id' => $device->id,
            'period_start' => $cycle->key(),
            'day' => $day,
        ]);

        $isNewDay = ! $row->exists;
        // El acumulado del periodo nunca baja dentro del mismo dia.
        $row->rx_bytes = max((int) $row->rx_bytes, $rx);
        $row->tx_bytes = max((int) $row->tx_bytes, $tx);
        $row->total_bytes = max((int) $row->total_bytes, $rx + $tx);
        $row->reported_at = $now;
        $row->save();

        // Alertas de consumo (25/50/75/90 % del paquete). Las filas se crean aqui; el envio, al terminar.
        $this->newAlerts = array_merge($this->newAlerts, $this->alerts->evaluate($device, $cycle->key()));

        if ($isNewDay) {
            // Un evento de consumo por equipo y dia, para el registro de actividad.
            DeviceEvent::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'device_id' => $device->id,
                'type' => EventType::DataUsage->value,
                'success' => null,
                'detail' => ['period_start' => $cycle->key(), 'cycle_total_bytes' => (int) $row->total_bytes],
                'occurred_at' => $now,
                'received_at' => $now,
            ]);
        }

        return 'stored';
    }
}
