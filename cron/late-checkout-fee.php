<?php

// SIRES - Detección y aplicación de cargos por Late Checkout
// Uso: php cron/late-checkout-fee.php
// Cron recomendado: 15 * * * * php /ruta/a/sires/cron/late-checkout-fee.php >> /ruta/a/sires/logs/late-checkout-fee.log 2>&1
//
// Detecta reservas EN_CASA cuya fecha de salida es hoy, pasada la hora de checkout (Configuracion.hora_checkout).
// Aplica un cargo de factor_late_checkout × precio pactado de la noche.
// La lógica está en PagoService::aplicarLateCheckout().
//
// NOTA: respaldo del MySQL EVENT cargo_late_checkout. No hace falta programarlo
// si el event_scheduler está activo; si corren los dos, no se cobra dos veces (late_fee_aplicado).

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
