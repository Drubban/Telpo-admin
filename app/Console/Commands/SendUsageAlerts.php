<?php

namespace App\Console\Commands;

use App\Services\UsageAlertService;
use Illuminate\Console\Command;

class SendUsageAlerts extends Command
{
    protected $signature = 'telpo:send-alerts';

    protected $description = 'Reintenta el envio de alertas de consumo pendientes o fallidas (correo y WhatsApp)';

    public function handle(UsageAlertService $alerts): int
    {
        $this->info('Alertas procesadas: '.$alerts->retryPending());

        return self::SUCCESS;
    }
}
