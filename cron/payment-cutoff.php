<?php

declare(strict_types=1);

// SIRES - Corte de Pagos 48hs antes del Check-in
// Uso: php cron/payment-cutoff.php
// Cron recomendado: 0 8 * * * php /ruta/a/sires/cron/payment-cutoff.php >> /ruta/a/sires/logs/payment-cutoff.log 2>&1
//
// NOTA: Este script está siendo reemplazado por el MySQL EVENT corte_pagos_48hs.
// Se mantiene como respaldo. La lógica principal está en PagoService::ejecutarCortePagos().

// Bootstrap
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Services\PagoService;

function main(): void
{
    error_log("[SIRES CRON] Iniciando corte de pagos 48hs...");

    try {
        $pagoService = new PagoService();
        $resultado = $pagoService->ejecutarCortePagos();
        error_log("[SIRES CRON] " . $resultado['message']);
    } catch (Exception $e) {
        error_log("[SIRES CRON ERROR] " . $e->getMessage());
    }
}

main();