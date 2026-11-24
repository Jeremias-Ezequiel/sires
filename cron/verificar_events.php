<?php

declare(strict_types=1);

// SIRES - Chequeo de los procesos automáticos (para un monitor externo o a mano).
// Uso: php cron/verificar_events.php
// Imprime el estado de cada proceso. Código de salida: 0 = todo bien, 1 = hay problemas.
// Ejemplo con cron + mail: */30 * * * * php /ruta/sires/cron/verificar_events.php || <aviso>

require_once __DIR__ . '/bootstrap.php';

use App\Services\MonitoreoService;

try {
    $monitoreo = new MonitoreoService();
    $estado = $monitoreo->estado();
    $alertas = $monitoreo->alertas();

    echo "event_scheduler: {$estado['scheduler']}\n";
    foreach ($estado['procesos'] as $clave => $p) {
        $ultima = $p['ultima_ok']['inicio'] ?? 'nunca';
        echo str_pad($clave, 22) . " última OK: {$ultima}  " . ($p['problema'] ?? 'OK') . "\n";
    }

    if ($alertas !== []) {
        foreach ($alertas as $a) {
            fwrite(STDERR, "[SIRES MONITOREO] {$a['texto']}\n");
        }
        exit(1);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[SIRES MONITOREO ERROR] " . $e->getMessage() . "\n");
    exit(1);
}
