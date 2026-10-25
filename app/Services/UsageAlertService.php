<?php

namespace App\Services;

use App\Models\Device;
use App\Models\UsageAlert;
use App\Support\BillingCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Alertas de consumo del paquete de datos (25/50/75/90 % por defecto). Crea UNA alerta por equipo,
 * periodo y umbral, y la avisa por correo y WhatsApp. Un fallo de envio nunca afecta a la
 * sincronizacion: se registra y se reintenta con `php artisan telpo:send-alerts`.
 */
class UsageAlertService
{
    private const MAX_ATTEMPTS = 5;

    public static function limitBytes(): int
    {
        return (int) round((float) config('telpo.data_plan_gb') * 1024 * 1024 * 1024);
    }

    /**
     * Revisa el consumo acumulado del periodo y crea las alertas de umbrales nuevos.
     *
     * @return list<UsageAlert> alertas nuevas que hay que avisar (la mas alta; las demas quedan solo registradas)
     */
    public function evaluate(Device $device, string $periodStart): array
    {
        $limit = self::limitBytes();
        $thresholds = (array) config('telpo.alert_thresholds');
        if ($limit <= 0 || $thresholds === []) {
            return [];
        }

        $total = (int) DB::table('data_usage_daily')
            ->where('device_id', $device->id)->where('period_start', $periodStart)
            ->max('total_bytes');

        $crossed = array_values(array_filter($thresholds, fn (int $t) => $total * 100 >= $t * $limit));
        if ($crossed === []) {
            return [];
        }
        sort($crossed);

        $existing = UsageAlert::where('device_id', $device->id)->where('period_start', $periodStart)
            ->pluck('threshold')->all();
        $fresh = array_values(array_diff($crossed, $existing));
        if ($fresh === []) {
            return [];
        }

        $top = max($fresh);
        $created = [];
        foreach ($fresh as $t) {
            $silent = $t !== $top;
            $alert = UsageAlert::create([
                'device_id' => $device->id,
                'period_start' => $periodStart,
                'threshold' => $t,
                'total_bytes' => $total,
                'limit_bytes' => $limit,
                'silent' => $silent,
                'email_status' => $silent ? 'skipped' : 'pending',
                'whatsapp_status' => $silent ? 'skipped' : 'pending',
            ]);
            if (! $silent) {
                $created[] = $alert;
            }
        }

        return $created;
    }

    /** Envia (o reintenta) los canales pendientes de una alerta. Nunca lanza excepciones. */
    public function notify(UsageAlert $alert): void
    {
        $alert->loadMissing('device');
        $errors = [];

        if (in_array($alert->email_status, ['pending', 'failed'], true)) {
            $alert->email_status = $this->safely(fn () => $this->sendEmail($alert), $errors, 'correo');
        }
        if (in_array($alert->whatsapp_status, ['pending', 'failed'], true)) {
            $alert->whatsapp_status = $this->safely(fn () => $this->sendWhatsapp($alert), $errors, 'whatsapp');
        }

        $alert->attempts = (int) $alert->attempts + 1;
        $alert->notify_error = $errors ? mb_substr(implode(' | ', $errors), 0, 500) : null;
        if (! in_array('pending', [$alert->email_status, $alert->whatsapp_status], true)
            && ! in_array('failed', [$alert->email_status, $alert->whatsapp_status], true)) {
            $alert->notified_at = now();
        }
        $alert->save();
    }

    /** Reintenta alertas con envios pendientes o fallidos (ultimos 3 dias). */
    public function retryPending(): int
    {
        $alerts = UsageAlert::query()
            ->where('silent', false)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('created_at', '>', now()->subDays(3))
            ->where(fn ($w) => $w->whereIn('email_status', ['pending', 'failed'])->orWhereIn('whatsapp_status', ['pending', 'failed']))
            ->orderBy('id')->limit(100)->get();

        foreach ($alerts as $alert) {
            $this->notify($alert);
        }

        return $alerts->count();
    }

    /** Texto base de la alerta (correo y WhatsApp). */
    public function message(UsageAlert $alert): string
    {
        $d = $alert->device;
        $cycle = BillingCycle::containing($alert->period_start->toDateString());
        $gb = fn (int $bytes) => number_format($bytes / 1073741824, 2, '.', '');

        return sprintf(
            "Se ha avistado un consumo del %d%% del paquete de datos.\nUnidad: %s\nIMEI: %s\nConsumo: %s GB de %s GB\nPeriodo: %s",
            $alert->threshold,
            $d->unit_number ?: 'Sin unidad',
            $d->imei ?: 'Sin IMEI',
            $gb($alert->total_bytes),
            rtrim(rtrim(number_format($alert->limit_bytes / 1073741824, 2, '.', ''), '0'), '.'),
            $cycle->label(),
        );
    }

    /** @return list<string> parametros del mensaje plantilla de Meta, en orden. */
    private function templateParams(UsageAlert $alert): array
    {
        $d = $alert->device;
        $cycle = BillingCycle::containing($alert->period_start->toDateString());

        return [
            (string) $alert->threshold,
            $d->unit_number ?: 'Sin unidad',
            $d->imei ?: 'Sin IMEI',
            number_format($alert->total_bytes / 1073741824, 2, '.', '').' GB',
            $cycle->label(),
        ];
    }

    private function sendEmail(UsageAlert $alert): string
    {
        $to = (array) config('telpo.alert_emails');
        if ($to === []) {
            return 'skipped';
        }

        $text = $this->message($alert);
        Mail::raw($text, function ($m) use ($to, $alert) {
            $m->to($to)->subject("Telpo: consumo de datos al {$alert->threshold}% ({$alert->device->unit_number})");
        });

        return 'sent';
    }

    private function sendWhatsapp(UsageAlert $alert): string
    {
        $driver = (string) config('telpo.whatsapp.driver', 'none');
        $numbers = array_values(array_filter(
            (array) config('telpo.alert_whatsapp'),
            fn ($n) => preg_match('/^\+?[0-9]{10,15}$/', (string) $n)
        ));
        if ($driver === 'none' || $numbers === []) {
            return 'skipped';
        }

        $timeout = (int) config('telpo.whatsapp.timeout', 10);
        $failures = [];

        foreach ($numbers as $number) {
            $to = '+'.ltrim($number, '+');
            try {
                $response = match ($driver) {
                    'twilio' => $this->twilio($to, $this->message($alert), $timeout),
                    'meta' => $this->meta($to, $this->templateParams($alert), $timeout),
                    default => throw new \RuntimeException("Driver de WhatsApp desconocido: {$driver}"),
                };
                if (! $response->successful()) {
                    $failures[] = "{$to}: HTTP {$response->status()}";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$to}: ".class_basename($e);
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException('WhatsApp: '.implode('; ', $failures));
        }

        return 'sent';
    }

    private function twilio(string $to, string $body, int $timeout)
    {
        $c = config('telpo.whatsapp.twilio');
        if (empty($c['sid']) || empty($c['token']) || empty($c['from'])) {
            throw new \RuntimeException('Faltan TWILIO_SID, TWILIO_TOKEN o TWILIO_WHATSAPP_FROM');
        }

        return Http::asForm()->timeout($timeout)->withBasicAuth($c['sid'], $c['token'])
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$c['sid']}/Messages.json", [
                'From' => 'whatsapp:+'.ltrim((string) $c['from'], '+'),
                'To' => 'whatsapp:'.$to,
                'Body' => $body,
            ]);
    }

    /** @param list<string> $params */
    private function meta(string $to, array $params, int $timeout)
    {
        $c = config('telpo.whatsapp.meta');
        if (empty($c['token']) || empty($c['phone_id'])) {
            throw new \RuntimeException('Faltan META_WHATSAPP_TOKEN o META_WHATSAPP_PHONE_ID');
        }

        return Http::timeout($timeout)->withToken($c['token'])
            ->post("https://graph.facebook.com/{$c['version']}/{$c['phone_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($to, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $c['template'],
                    'language' => ['code' => $c['language']],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => $p], $params),
                    ]],
                ],
            ]);
    }

    /** Ejecuta un envio; devuelve el estado y acumula el error en $errors sin propagarlo. */
    private function safely(callable $send, array &$errors, string $channel): string
    {
        try {
            return $send();
        } catch (\Throwable $e) {
            report($e);
            $errors[] = "{$channel}: ".mb_substr($e->getMessage(), 0, 160);

            return 'failed';
        }
    }
}
