<?php

// SIRES - Detección y aplicación de cargos por Late Checkout
// Uso: php cron/late-checkout-fee.php
// Cron recomendado: 15 * * * * php /ruta/a/sires/cron/late-checkout-fee.php >> /ruta/a/sires/logs/late-checkout-fee.log 2>&1
//
// Detecta reservas EN_CASA cuya fecha de salida es hoy y hora > 11:00 AM sin checkout realizado.
// Aplica cargo automatico de media noche extra.

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Models\Reserva;
use App\Models\ResumenPago;
use PDOException;

function main(): void
{
    error_log("[SIRES CRON] Iniciando verificación de Late Checkout...");

    $db = (new \App\Config\Database())->getConnection();
    $fechaActual = date('Y-m-d');
    $horaActual  = date('H:i:s');

    $stmt = $db->prepare("
        SELECT r.id, r.id_habitacion, r.fecha_salida, rp.id AS id_resumen,
               rp.monto_cobrado, rp.saldo_pendiente, rp.monto_total,
               h.precio_noche_base
        FROM Reservas r
        JOIN Resumen_Pago rp ON rp.id_reserva = r.id
        JOIN Habitaciones h ON h.id = r.id_habitacion
        WHERE r.id_estado_reserva = :encasa
          AND r.fecha_salida = :fecha_hoy
          AND :hora_actual >= '11:00:00'
          AND (r.observaciones IS NULL OR r.observaciones NOT LIKE '%Late Checkout Fee Aplicado%')
    ");
    $stmt->execute([
        ':encasa'     => Reserva::ESTADO_EN_CASA,
        ':fecha_hoy'  => $fechaActual,
        ':hora_actual' => $horaActual
    ]);

    $conLateCheckout = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $contador = 0;
    foreach ($conLateCheckout as $row) {
        $db->beginTransaction();
        try {
            $idReserva    = (int)$row['id'];
            $idResumen    = (int)$row['id_resumen'];
            $precioNoche  = (float)$row['precio_noche_base'];
            $feeLateCheckout = round($precioNoche / 2, 2);

            $nuevoTotal       = (float)$row['monto_total'] + $feeLateCheckout;
            $nuevoSaldo       = (float)$row['saldo_pendiente'] + $feeLateCheckout;

            $updResumen = $db->prepare(
                "UPDATE Resumen_Pago
                 SET monto_total = :total, saldo_pendiente = :saldo
                 WHERE id = :id"
            );
            $updResumen->execute([
                ':total' => $nuevoTotal,
                ':saldo' => $nuevoSaldo,
                ':id'    => $idResumen
            ]);

            $updReserva = $db->prepare(
                "UPDATE Reservas
                 SET observaciones = CONCAT(
                     COALESCE(observaciones, ''),
                     ' | Late Checkout Fee Aplicado: $', :fee, ' por salida despues de las 11:00 AM.'
                 )
                 WHERE id = :id"
            );
            $updReserva->execute([
                ':fee' => number_format($feeLateCheckout, 2, ',', '.'),
                ':id'  => $idReserva
            ]);

            $db->commit();
            $contador++;
            error_log("[SIRES CRON] Reserva #" . $idReserva .
                ": Late Checkout fee de $" . number_format($feeLateCheckout, 2, ',', '.') . " aplicado.");
        } catch (Exception $e) {
            $db->rollBack();
            error_log("[SIRES CRON ERROR] " . $e->getMessage());
        }
    }

    error_log("[SIRES CRON] Verificacion de Late Checkout completada. " . $contador . " cargo(s) aplicado(s).");
}

main();