<?php

declare(strict_types=1);

namespace App\Services\Reserva;

use Exception;
use App\Models\Clientes;
use App\Models\Reserva;
use App\Models\Habitacion;
use App\Models\ResumenPago;
use App\Models\TransaccionPago;
use App\Dominio\ReglasPago;
use App\Dominio\ReglasReserva;
use App\Dominio\Tarifa;
use PDO;
use PDOException;
use App\Services\ConfiguracionService;
use App\Services\PagoService;
use App\Services\MailService;
use App\Services\ComprobanteService;

/**
 * Cancelación y No-Show (manual y la liberación automática del No-Show con pago, respaldo
 * del EVENT liberacion_no_show).
 */
final class BajaReservaService extends ServicioReservaBase
{
    public function cancelar(int $idReserva): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }

        $estadoActual = (int)$reservaData['id_estado_reserva'];
        if ($estadoActual !== Reserva::ESTADO_PENDIENTE && $estadoActual !== Reserva::ESTADO_CONFIRMADA) {
            throw new Exception("Solo se pueden cancelar reservas pendientes o confirmadas.");
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();
        $antes = $this->auditoria->foto('reserva', $idReserva);

        try {
            $ok = $this->reservaModel->cambiarEstado($idReserva, Reserva::ESTADO_CANCELADA, $estadoActual);
            if (!$ok) {
                throw new Exception("No se pudo cancelar la reserva.");
            }

            // Política de cancelación: sin cargo con suficiente anticipación; si no, se retienen
            // N noches (con tope en lo cobrado). El resto de lo cobrado queda A Reembolsar,
            // sin importar si la reserva estaba pendiente o confirmada.
            // Condiciones vigentes cuando se hizo la reserva (no las actuales)
            $politica = ConfiguracionService::politicaReserva($reservaData['fecha_alta']);
            $horasGratis = $politica->cancelacionGratisHoras;
            $montoRetenido = ReglasReserva::retencionCancelacion(
                self::precioNocheReserva($reservaData),
                Tarifa::noches($reservaData['fecha_entrada'], $reservaData['fecha_salida']),
                ReglasReserva::horasAnticipacion($reservaData['fecha_entrada'], time()),
                $politica
            );
            $estadoPago = (new PagoService())->aplicarRetencion($idReserva, $montoRetenido);

            // No se toca la habitación: una reserva pendiente o confirmada nunca la ocupó,
            // y su estado actual (Ocupada, Sucia, etc.) puede corresponder a otro huésped.

            $this->auditoria->registrar('reserva.cancelar', 'reserva', $idReserva, 'Cancelación', $antes);
            $db->commit();

            $mensaje = 'Reserva cancelada exitosamente.';
            if ($estadoPago !== null) {
                $resumen = $this->resumenModel->getByReserva($idReserva);
                $retenido = $resumen !== null ? $resumen->getMontoRetenido() : 0.0;
                $mensaje .= $retenido > 0
                    ? ' Por cancelar con menos de ' . $horasGratis .
                      ' h de anticipación se retienen $' . number_format($retenido, 2, ',', '.') . '.'
                    : ' Sin cargo por cancelación.';
                $mensaje .= $estadoPago === \App\Models\EstadoPago::A_REEMBOLSAB
                    ? ' El resto queda A Reembolsar.'
                    : '';
            }
            return ['success' => true, 'message' => $mensaje];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Día en que se libera un No-Show con pago: entrada + noches retenidas, sin pasar la salida. */
    private static function fechaLiberacionNoShow(array $reserva): string
    {
        return ReglasReserva::fechaLiberacionNoShow(
            (string)$reserva['fecha_entrada'],
            (string)$reserva['fecha_salida'],
            ConfiguracionService::noShowNochesRetenidas($reserva['fecha_alta'])
        );
    }

    public function marcarNoShow(int $idReserva): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }
        if ((int)$reservaData['id_estado_reserva'] !== Reserva::ESTADO_CONFIRMADA) {
            throw new Exception("Solo se puede marcar como No-Show reservas confirmadas.");
        }

        $errorNoShow = ReglasReserva::errorNoShow(
            (string)$reservaData['fecha_entrada'],
            date('Y-m-d'),
            date('H:i'),
            ConfiguracionService::horaNoShow()
        );
        if ($errorNoShow !== null) {
            throw new Exception($errorNoShow);
        }

        $idHabitacion = (int)$reservaData['id_habitacion'];

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();
        $antes = $this->auditoria->foto('reserva', $idReserva);

        try {
            $resumen = $this->resumenModel->getByReserva($idReserva);
            $tienePago = $resumen !== null && $resumen->getMontoPagado() > 0;

            if ($tienePago) {
                $ok = $this->reservaModel->cambiarEstado(
                    $idReserva,
                    Reserva::ESTADO_NO_SHOW_CON_PAGO,
                    Reserva::ESTADO_CONFIRMADA
                );
                if (!$ok) {
                    throw new Exception("No se pudo marcar la reserva como No-Show.");
                }

                // Se retiene solo si está libre: si hay otro huésped o falta limpiarla, se deja como está.
                $this->habitacionModel->cambiarEstado(
                    $idHabitacion,
                    Habitacion::ESTADO_RETENIDA,
                    Habitacion::ESTADO_DISPONIBLE
                );

                $this->auditoria->registrar('reserva.no_show', 'reserva', $idReserva, 'No-Show', $antes);
                $db->commit();
                $liberacion = self::fechaLiberacionNoShow($reservaData);
                $noches = ConfiguracionService::noShowNochesRetenidas($reservaData['fecha_alta']);
                return [
                    'success' => true,
                    'message' => 'No-Show registrado. La habitación queda retenida hasta el ' .
                        date('d/m/Y', strtotime($liberacion)) . ' a las ' . ConfiguracionService::horaCheckout() .
                        '. Si el huésped no llega, se cobra' . ($noches === 1 ? ' 1 noche' : 'n ' . $noches . ' noches') .
                        ' como penalidad y el resto de lo pagado queda A Reembolsar.'
                ];
            } else {
                $ok = $this->reservaModel->cambiarEstado(
                    $idReserva,
                    Reserva::ESTADO_NO_SHOW,
                    Reserva::ESTADO_CONFIRMADA
                );
                if (!$ok) {
                    throw new Exception("No se pudo marcar la reserva como No-Show.");
                }

                // Sin pago no se retiene la habitación. Tampoco se la cambia de estado:
                // la reserva nunca la ocupó y su estado actual puede ser de otro huésped.
                $this->auditoria->registrar('reserva.no_show', 'reserva', $idReserva, 'No-Show', $antes);
                $db->commit();
                return ['success' => true, 'message' => 'No-Show registrado. La habitación no queda retenida (sin pago).'];
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Libera las reservas retenidas por No-Show con pago al terminar la retención:
     * el día entrada + noshow_noches_retenidas (sin pasar la salida) a la hora de checkout.
     * - La reserva pasa a NO_SHOW.
     * - Se retienen esas noches al precio pactado (tope: lo cobrado); el resto queda A Reembolsar.
     * - La habitación pasa de Retenida a Disponible.
     * Misma lógica que el EVENT liberacion_no_show. Respaldo: cron/no-show-release.php.
     */
    public function liberarNoShowConPago(): array
    {
        $db = $this->reservaModel->getConnection();
        $fechaActual = date('Y-m-d');
        // Noches retenidas según la política vigente al crear cada reserva (igual que el EVENT)
        $nochesPorDefecto = \App\Config\Politicas::NOSHOW_NOCHES_RETENIDAS;

        $stmt = $db->prepare("
            SELECT r.id, r.id_habitacion,
                   DATEDIFF(t.fecha_liberacion, r.fecha_entrada) AS noches_retenidas,
                   COALESCE(r.precio_noche_pactado, h.precio_noche_base) AS precio_noche
            FROM Reservas r
            JOIN Habitaciones h ON h.id = r.id_habitacion
            JOIN (
                SELECT rr.id, LEAST(DATE_ADD(rr.fecha_entrada, INTERVAL COALESCE((
                           SELECT CAST(c.valor AS UNSIGNED) FROM Configuracion c
                           WHERE c.clave = 'noshow_noches_retenidas' AND c.vigente_desde <= rr.fecha_alta
                           ORDER BY c.vigente_desde DESC LIMIT 1
                       ), :noches) DAY), rr.fecha_salida) AS fecha_liberacion
                FROM Reservas rr
                WHERE rr.id_estado_reserva = :noshow_pago_sub
            ) t ON t.id = r.id
            WHERE r.id_estado_reserva = :noshow_pago
              AND (t.fecha_liberacion < :fecha_hoy
                   OR (t.fecha_liberacion = :fecha_hoy_2 AND :hora_actual >= :hora_checkout))
        ");
        $stmt->execute([
            ':noches'          => $nochesPorDefecto,
            ':noshow_pago_sub' => Reserva::ESTADO_NO_SHOW_CON_PAGO,
            ':noshow_pago'     => Reserva::ESTADO_NO_SHOW_CON_PAGO,
            ':fecha_hoy'       => $fechaActual,
            ':fecha_hoy_2'     => $fechaActual,
            ':hora_actual'     => date('H:i:s'),
            ':hora_checkout'   => ConfiguracionService::horaCheckoutSql(),
        ]);

        $aLiberar = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $pagoService = new PagoService();

        $contador = 0;
        foreach ($aLiberar as $row) {
            $db->beginTransaction();
            try {
                $idReserva    = (int)$row['id'];
                $idHabitacion = (int)$row['id_habitacion'];

                $updR = $db->prepare(
                    "UPDATE Reservas
                     SET id_estado_reserva = :noshow,
                         observaciones = CONCAT(COALESCE(observaciones, ''), " . Reserva::SQL_SEPARADOR_OBS . ",
                             'Liberacion automatica tras retencion por No-Show con pago.')
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

                $pagoService->aplicarRetencion(
                    $idReserva,
                    ReglasReserva::retencionNoShow((float)$row['precio_noche'], (int)$row['noches_retenidas'])
                );

                $this->habitacionModel->cambiarEstado(
                    $idHabitacion,
                    Habitacion::ESTADO_DISPONIBLE,
                    Habitacion::ESTADO_RETENIDA
                );

                $db->commit();
                $contador++;
                error_log("[SIRES CRON] Reserva #" . $idReserva . " liberada de No-Show con pago.");
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("[SIRES CRON ERROR] " . $e->getMessage());
            }
        }

        return [
            'success'    => true,
            'procesadas' => $contador,
            'message'    => 'Liberacion de No-Show completada. ' . $contador . ' reserva(s) liberada(s).',
        ];
    }
}
