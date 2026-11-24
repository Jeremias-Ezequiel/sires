<?php

declare(strict_types=1);

// SIRES - Cola de mails al huésped: encola los recordatorios del día y manda los pendientes
// (confirmaciones, recordatorios y comprobantes de pago), con reintentos.
// Uso: php cron/mails.php
// Cron (OBLIGATORIO para que salgan los mails; no hay EVENT equivalente):
//   */5 * * * * php /ruta/a/sires/cron/mails.php >> /ruta/a/sires/logs/mails.log 2>&1
// La lógica está en MailService::procesarCola(). Cada corrida queda en Ejecuciones_Automaticas.

require_once __DIR__ . '/bootstrap.php';

use App\Services\MailService;
use App\Services\MonitoreoService;

try {
    $resultado = (new MonitoreoService())->ejecutarConRegistro('envio_mails',
        fn() => (new MailService())->procesarCola());
    error_log('[SIRES CRON] ' . $resultado['message']);
} catch (Throwable $e) {
    error_log('[SIRES CRON ERROR] ' . $e->getMessage());
    exit(1);
}
