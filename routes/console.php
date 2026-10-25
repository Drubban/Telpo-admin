<?php

use Illuminate\Support\Facades\Schedule;

// Limpia eventos antiguos una vez al dia (por defecto conserva 400 dias).
Schedule::command('telpo:prune')->dailyAt('03:30');

// Reintenta alertas de consumo que no se pudieron enviar (requiere el programador: php artisan schedule:work).
Schedule::command('telpo:send-alerts')->everyTenMinutes();
