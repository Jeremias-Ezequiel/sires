<?php

// SIRES - Liberación de habitaciones retenidas por No-Show con Pago
// Uso: php cron/no-show-release.php
// Cron recomendado: 30 * * * * php /ruta/a/sires/cron/no-show-release.php >> /ruta/a/sires/logs/no-show-release.log 2>&1
//
// Libera habitaciones retenidas por No-Show con pago al dia siguiente del checkout a las 11:00 AM.

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Models\Reserva;
use App\Models\Habitacion;
use PDOException;

function main(): void
{
    error_log("[SIRES CRON] Iniciando liberación de No-Show con pago...");

    $db = (new \App\Config\Database())->getConnection();
    $fechaActual = date('Y-m-d');
    $horaActual  = date('H:i:s');

    $stmt = $db->prepare("
        SELECT r.id, r.id_habitacion
        FROM Reservas r
        WHERE r.id_estado_reserva = :noshow_pago
          AND (r.fecha_salida < :fecha_hoy
               OR (r.fecha_salida = :fecha_hoy_2 AND :hora_actual >= '11:00:00'))
    ");
    $stmt->execute([
        ':noshow_pago' => Reserva::ESTADO_NO_SHOW_CON_PAGO,
        ':fecha_hoy'   => $fechaActual,
        ':fecha_hoy_2' => $fechaActual,
        ':hora_actual' => $horaActual
    ]);

    $aLiberar = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $contador = 0;
    foreach ($aLiberar as $row) {
        $db->beginTransaction();
        try {
            $idReserva    = (int)$row['id'];
            $idHabitacion = (int)$row['id_habitacion'];

            $updR = $db->prepare(
                "UPDATE Reservas
                 SET id_estado_reserva = :noshow,
                     observaciones = CONCAT(COALESCE(observaciones, ''),
                         ' | Liberacion automatica tras retencion por No-Show con pago.')
                 WHERE id = :id AND id_estado_reserva = :actual"
            );
            $updR->execute([
                ':noshow' => Reserva::ESTADO_NO_SHOW,
                ':id'     => $idReserva,
                ':actual' => Reserva::ESTADO_NO_SHOW_CON_PAGO
            ]);

            if ($updR->rowCount() === 0) {
                $db->rollBack();
                continue;
            }

            $updH = $db->prepare(
                "UPDATE Habitaciones SET id_estado_habitacion = :disp
                 WHERE id = :id AND id_estado_habitacion = :ocupada"
            );
            $updH->execute([
                ':disp'    => Habitacion::ESTADO_DISPONIBLE,
                ':id'      => $idHabitacion,
                ':ocupada' => Habitacion::ESTADO_OCUPADA
            ]);

            $db->commit();
            $contador++;
            error_log("[SIRES CRON] Reserva #" . $idReserva . " liberada de No-Show con pago.");
        } catch (Exception $e) {
            $db->rollBack();
            error_log("[SIRES CRON ERROR] " . $e->getMessage());
        }
    }

    error_log("[SIRES CRON] Liberacion de No-Show completada. " . $contador . " reserva(s) liberada(s).");
}

main();