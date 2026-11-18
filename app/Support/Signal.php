<?php

namespace App\Support;

/** Semaforo de red que calcula la Telpo cada 10 minutos. */
final class Signal
{
    public const OK = 0;        // verde: estable
    public const UNSTABLE = 1;  // amarillo: intermitencia
    public const PROBLEM = 2;   // rojo: apagado, con problemas o sin datos

    /** Motivos que acepta el servidor (cualquier otro se descarta). */
    public const REASONS = ['ok', 'slow', 'unstable', 'no_internet', 'off'];

    public const SAMPLE_INTERVAL_MINUTES = 10;

    /** Dias que se conservan las muestras del historial. */
    public const RETENTION_DAYS = 30;
}
