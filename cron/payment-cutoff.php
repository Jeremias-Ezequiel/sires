<?php

// SIRES - Corte de Pagos 48hs antes del Check-in
// Uso: php cron/payment-cutoff.php
// Cron recomendado: 0 8 * * * php /ruta/a/sires/cron/payment-cutoff.php >> /ruta/a/sires/logs/payment-cutoff.log 2>&1
//
// Verifica reservas con check-in en 48hs que NO estén pagadas al 100%.
// - Pendiente (0%): cancela sin reembolso
// - Pago Parcial: reembolsa y cancela
// - Pagado Total: no hace nada

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Models\Reserva;
use App\Models\ResumenPago;
use App\Models\Habitacion;
use PDOException;

function main(): void
{
    error_log("[SIRES CRON] Iniciando corte de pagos 48hs...");

    $db = (new \App\Config\Database())->getConnection();
    $fechaLimite = date('Y-m-d', time() + 48 * 3600);

    $stmt = $db->prepare("
        SELECT r.id, r.id_habitacion, rp.id AS id_resumen,
               rp.id_estado_pago, rp.monto_cobrado, rp.monto_total
        FROM Reservas r
        JOIN Resumen_Pago rp ON rp.id_reserva = r.id
        WHERE r.fecha_entrada = :fecha
          AND r.id_estado_reserva IN (:pendiente, :confirmada)
          AND rp.id_estado_pago <> :pagado_total
    ");
    $stmt->execute([
        ':fecha'         => $fechaLimite,
        ':pendiente'     => Reserva::ESTADO_PENDIENTE,
        ':confirmada'    => Reserva::ESTADO_CONFIRMADA,
        ':pagado_total'  => ResumenPago::ESTADO_PAGADO_TOTAL
    ]);

    $afectadas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($afectadas)) {
        error_log("[SIRES CRON] Corte de pagos: No se encontraron reservas pendientes para la fecha " . $fechaLimite);
        return;
    }

    $contador = 0;
    foreach ($afectadas as $row) {
        $db->beginTransaction();
        try {
            $idReserva  = (int)$row['id'];
            $idResumen  = (int)$row['id_resumen'];
            $estadoPago = (int)$row['id_estado_pago'];
            $motivo     = "";

            if ($estadoPago === ResumenPago::ESTADO_PAGO_PARCIAL) {
                $upd = $db->prepare(
                    "UPDATE Resumen_Pago
                     SET id_estado_pago = :reembolsado, monto_cobrado = 0, saldo_pendiente = 0
                     WHERE id = :id"
                );
                $upd->execute([
                    ':reembolsado' => ResumenPago::ESTADO_REEMBOLSADO,
                    ':id'          => $idResumen
                ]);
                $motivo = "Pago parcial reembolsado. ";
            }

            $updR = $db->prepare(
                "UPDATE Reservas
                 SET id_estado_reserva = :cancelada,
                     observaciones = CONCAT(
                         COALESCE(observaciones, ''),
                         ' | Cancelación automática 48hs antes del check-in. ',
                         :motivo,
                         'Fecha: ', NOW()
                     )
                 WHERE id = :id"
            );
            $updR->execute([
                ':cancelada' => Reserva::ESTADO_CANCELADA,
                ':motivo'    => $motivo,
                ':id'        => $idReserva
            ]);

            $updH = $db->prepare(
                "UPDATE Habitaciones SET id_estado_habitacion = :disp WHERE id = :id"
            );
            $updH->execute([
                ':disp' => Habitacion::ESTADO_DISPONIBLE,
                ':id'   => (int)$row['id_habitacion']
            ]);

            $db->commit();
            $contador++;
            error_log("[SIRES CRON] Reserva #" . $idReserva . " cancelada por falta de pago total.");
        } catch (Exception $e) {
            $db->rollBack();
            error_log("[SIRES CRON ERROR] Reserva #" . $row['id'] . ": " . $e->getMessage());
        }
    }

    error_log("[SIRES CRON] Corte de pagos finalizado. " . $contador . " reserva(s) afectada(s).");
}

main();