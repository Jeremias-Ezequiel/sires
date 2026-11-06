<?php

// SIRES - Liberación de habitaciones retenidas por No-Show con Pago
// Uso: php cron/no-show-release.php
// Cron recomendado: 30 * * * * php /ruta/a/sires/cron/no-show-release.php >> /ruta/a/sires/logs/no-show-release.log 2>&1
//
// Libera habitaciones retenidas por No-Show con pago al llegar la fecha de salida a las Politicas::HORA_CHECKOUT.
// La lógica está en ReservaService::liberarNoShowConPago().

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Services\ReservaService;

error_log("[SIRES CRON] Iniciando liberación de No-Show con pago...");

try {
    $resultado = (new ReservaService())->liberarNoShowConPago();
    error_log("[SIRES CRON] " . $resultado['message']);
} catch (Throwable $e) {
    error_log("[SIRES CRON ERROR] " . $e->getMessage());
}
