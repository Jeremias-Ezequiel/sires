<?php

// SIRES - Liberación de habitaciones retenidas por No-Show con Pago
// Uso: php cron/no-show-release.php
// Cron recomendado: 30 * * * * php /ruta/a/sires/cron/no-show-release.php >> /ruta/a/sires/logs/no-show-release.log 2>&1
//
// Libera habitaciones retenidas por No-Show con pago al llegar la fecha de salida, a la hora de checkout.
// La lógica está en ReservaService::liberarNoShowConPago().
//
// NOTA: respaldo del MySQL EVENT liberacion_no_show. No hace falta programarlo
// si el event_scheduler está activo; si corren los dos, no hay efecto doble.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Services\MonitoreoService;
use App\Services\ReservaService;

error_log("[SIRES CRON] Iniciando liberación de No-Show con pago...");

try {
    $resultado = (new MonitoreoService())->ejecutarConRegistro('liberacion_no_show',
        fn() => (new ReservaService())->liberarNoShowConPago());
    error_log("[SIRES CRON] " . $resultado['message']);
} catch (Throwable $e) {
    error_log("[SIRES CRON ERROR] " . $e->getMessage());
}
