<?php

declare(strict_types=1);

// SIRES - Corte de Pagos N horas (Configuracion.horas_corte_pago, 48 por defecto) antes del Check-in
// Uso: php cron/payment-cutoff.php
// Cron recomendado: 0 8 * * * php /ruta/a/sires/cron/payment-cutoff.php >> /ruta/a/sires/logs/payment-cutoff.log 2>&1
//
// NOTA: respaldo del MySQL EVENT corte_pagos. No hace falta programarlo
// si el event_scheduler está activo. La lógica está en PagoService::ejecutarCortePagos().

require_once __DIR__ . '/bootstrap.php';

use App\Services\PagoService;

function main(): void
{
    error_log("[SIRES CRON] Iniciando corte de pagos " . App\Services\ConfiguracionService::horasCortePago() . "hs...");

    try {
        $pagoService = new PagoService();
        $resultado = $pagoService->ejecutarCortePagos();
        error_log("[SIRES CRON] " . $resultado['message']);
    } catch (Exception $e) {
        error_log("[SIRES CRON ERROR] " . $e->getMessage());
    }
}

main();