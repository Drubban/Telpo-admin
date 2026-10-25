<?php

// Configuracion propia del sistema Telpo. Todo lo ajustable vive aqui y en .env.
return [

    // Clave compartida que usan las Telpo SOLO para darse de alta (enroll). Despues cada equipo
    // recibe su propio token. Debe coincidir con telpo_enrollment_key en la app Android.
    'enrollment_key' => env('TELPO_ENROLLMENT_KEY'),

    // true = se permite operar por HTTP (red local, pruebas). En produccion con dominio: false.
    'allow_http' => (bool) env('TELPO_ALLOW_HTTP', false),

    // App de cobro cuya version se controla (nombre de paquete Android).
    'billing_app_package' => env('TELPO_BILLING_APP_PACKAGE', 'mx.transport.charge'),

    // Paquete de datos de cada SIM y umbrales de alerta (porcentaje del paquete). 1 GB = 1024 MB,
    // igual que en pantallas y exportes.
    'data_plan_gb' => (float) env('TELPO_DATA_PLAN_GB', 36),
    'alert_thresholds' => array_values(array_filter(
        array_map('intval', explode(',', (string) env('TELPO_ALERT_THRESHOLDS', '25,50,75,90,100'))),
        fn (int $t) => $t >= 1 && $t <= 100
    )),

    // Destinatarios de las alertas de consumo (separados por coma). Vacio = ese canal no se usa.
    'alert_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('TELPO_ALERT_EMAILS', ''))))),
    'alert_whatsapp' => array_values(array_filter(array_map('trim', explode(',', (string) env('TELPO_ALERT_WHATSAPP', ''))))),

    // WhatsApp: none | twilio | meta. Las claves viven solo en .env.
    'whatsapp' => [
        'driver' => env('TELPO_WHATSAPP_DRIVER', 'none'),
        'timeout' => 10,
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_WHATSAPP_FROM'), // ej. +14155238886 (sandbox)
        ],
        'meta' => [
            'token' => env('META_WHATSAPP_TOKEN'),
            'phone_id' => env('META_WHATSAPP_PHONE_ID'),
            'template' => env('META_WHATSAPP_TEMPLATE', 'alerta_consumo_telpo'),
            'language' => env('META_WHATSAPP_LANG', 'es_MX'),
            'version' => env('META_WHATSAPP_API_VERSION', 'v20.0'),
        ],
    ],

    // Dia del mes en que inicia el periodo de consumo (1 mes). Por defecto el 20.
    // El periodo corre del dia de corte hasta la vispera del siguiente corte (ej. 20 oct - 19 nov).
    'billing_cutoff_day' => (int) env('TELPO_BILLING_CUTOFF_DAY', 20),

    // Zona horaria para periodos, dias y nombres de exportes. La BD guarda todo en UTC.
    'timezone' => env('TELPO_TIMEZONE', 'America/Mexico_City'),

    // Un equipo se considera en linea si sincronizo dentro de este numero de minutos.
    'online_minutes' => (int) env('TELPO_ONLINE_MINUTES', 30),

    // PBKDF2-HMAC-SHA256 para las 3 contrasenas que se envian a los equipos (nunca en claro).
    'pbkdf2_iterations' => (int) env('TELPO_PBKDF2_ITERATIONS', 120000),
    'password_min_length' => 6,

    // Sal de los IDs de ruta hasheados. Si no se define se usa APP_KEY.
    'hashids_salt' => env('HASHIDS_SALT', env('APP_KEY')),
    'hashids_min_length' => 12,

    // 1 MB = 1024 * 1024 bytes en pantallas y exportes.
    'bytes_per_mb' => 1048576,

    // Zona de operacion de las Telpo (por defecto, Mexico). Sirve para rechazar coordenadas de topes
    // con latitud y longitud invertidas o mal copiadas.
    'geo_bounds' => [
        'lat_min' => (float) env('TELPO_LAT_MIN', 14.0),
        'lat_max' => (float) env('TELPO_LAT_MAX', 33.0),
        'lng_min' => (float) env('TELPO_LNG_MIN', -119.0),
        'lng_max' => (float) env('TELPO_LNG_MAX', -86.0),
    ],

    // Limite de filas para exportar a PDF (CSV y XLSX no tienen limite practico).
    'pdf_max_rows' => 5000,
];
