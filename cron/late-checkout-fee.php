<?php

// SIRES - Detección y aplicación de cargos por Late Checkout
// Uso: php cron/late-checkout-fee.php
// Cron recomendado: 15 * * * * php /ruta/a/sires/cron/late-checkout-fee.php >> /ruta/a/sires/logs/late-checkout-fee.log 2>&1
//
// Detecta reservas EN_CASA cuya fecha de salida es hoy y hora >= Politicas::HORA_CHECKOUT sin checkout realizado.
// Aplica un cargo de Politicas::FACTOR_LATE_CHECKOUT × noche base.
// La lógica está en PagoService::aplicarLateCheckout().

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Services\PagoService;

error_log("[SIRES CRON] Iniciando verificación de Late Checkout...");

try {
    $resultado = (new PagoService())->aplicarLateCheckout();
    error_log("[SIRES CRON] " . $resultado['message']);
} catch (Throwable $e) {
    error_log("[SIRES CRON ERROR] " . $e->getMessage());
}
