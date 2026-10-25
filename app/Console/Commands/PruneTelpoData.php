<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DeviceEvent;
use Illuminate\Console\Command;

class PruneTelpoData extends Command
{
    protected $signature = 'telpo:prune {--days=400 : Dias de historial que se conservan}';

    protected $description = 'Borra eventos y bitacora de administracion mas antiguos que N dias';

    public function handle(): int
    {
        $limit = now()->subDays(max(30, (int) $this->option('days')));

        // Los avisos de tope son muchos y solo sirven para control reciente: se conservan 120 dias.
        $bumpPasses = DeviceEvent::where('type', 'bump_alert')->where('occurred_at', '<', now()->subDays(120))->delete();

        $events = DeviceEvent::where('occurred_at', '<', $limit)->delete();
        $audit = AuditLog::where('created_at', '<', $limit)->delete();

        $this->info("Avisos de tope borrados: {$bumpPasses}. Eventos borrados: {$events}. Bitacora borrada: {$audit}.");

        return self::SUCCESS;
    }
}
