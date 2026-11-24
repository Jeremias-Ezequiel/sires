<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use App\Models\Reserva;
use App\Models\ResumenPago;
use App\Models\EstadoPago;
use App\Models\TransaccionPago;
use App\Models\Habitacion;
use App\Dominio\ReglasPago;
use App\Dominio\Tarifa;
use PDO;
use PDOException;

class PagoService
{
    private ResumenPago $resumenModel;
    private TransaccionPago $transaccionModel;
    private Reserva $reservaModel;

    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->auditoria = new AuditoriaService();
        $this->resumenModel     = new ResumenPago();
        $this->transaccionModel = new TransaccionPago();
        $this->reservaModel     = new Reserva();
    }

    public function generarResumen(array $reserva): ?ResumenPago
    {
        if ((int)$reserva['id_estado_reserva'] === Reserva::ESTADO_CANCELADA) {
            return null;
        }

        $existente = $this->resumenModel->getByReserva((int)$reserva['id']);
        if ($existente !== null) {
            return $existente;
        }

        if (new \DateTime($reserva['fecha_salida']) <= new \DateTime($reserva['fecha_entrada'])) {
            throw new Exception("La reserva no tiene fechas válidas para calcular el total.");
        }

        $noches     = Tarifa::noches($reserva['fecha_entrada'], $reserva['fecha_salida']);
        $precioBase = (float)$reserva['precio_noche_base'];
        $idTipo     = (int)$reserva['id_tipo_habitacion'];
        $huespedes  = (int)$reserva['cantidad_huespedes'];
        $precioEfectivo = Habitacion::precioEfectivoNoche($precioBase, $idTipo, $huespedes);
        $total      = Tarifa::total($precioEfectivo, $noches);

        if ($total <= 0) {
            throw new Exception("No se pudo calcular un total válido para la reserva.");
        }

        $this->reservaModel->actualizarPrecioPactado((int)$reserva['id'], $precioEfectivo);

        $resumen = new ResumenPago();
        $resumen->setIdReserva((int)$reserva['id']);
        $resumen->setIdEstadoPago(ResumenPago::ESTADO_PENDIENTE);
        $resumen->setTotal($total);
        $resumen->setMontoPagado(0.0);
        $resumen->setSaldoPendiente($total);

        $saved = $this->resumenModel->save($resumen);
        if (!$saved) {
            throw new Exception("No se pudo crear el resumen de pago.");
        }

        return $this->resumenModel->getByReserva((int)$reserva['id']);
    }

    public function registrarPago(int $idReserva, int $idMetodoPago, float $montoAbonado, int $registradoPor): array
    {
        if ($idReserva <= 0) {
            throw new Exception("Ocurrió un error al seleccionar la reserva.");
        }
        if ($idMetodoPago <= 0) {
            throw new Exception("Debe seleccionar un método de pago.");
        }
        if ($montoAbonado <= 0) {
            throw new Exception("El monto a abonar debe ser mayor a 0.");
        }

        $reserva = $this->reservaModel->findById($idReserva);
        if (!$reserva) {
            throw new Exception("La reserva no existe.");
        }
        // NO_SHOW_CON_PAGO no se bloquea: el check-in desde ese estado exige completar el pago.
        $estadosSinPagos = [
            Reserva::ESTADO_CANCELADA  => 'cancelada',
            Reserva::ESTADO_NO_SHOW    => 'en No-Show',
            Reserva::ESTADO_FINALIZADA => 'finalizada',
        ];
        $estadoReserva = (int)$reserva['id_estado_reserva'];
        if (isset($estadosSinPagos[$estadoReserva])) {
            throw new Exception("No se pueden registrar pagos sobre una reserva " . $estadosSinPagos[$estadoReserva] . ".");
        }

        $db = $this->resumenModel->getConnection();
        $db->beginTransaction();
        $antes = $this->auditoria->foto('reserva', $idReserva);

        try {
            $lockResumen = $db->prepare(
                "SELECT id, id_estado_pago, monto_cobrado, saldo_pendiente
                 FROM Resumen_Pago WHERE id_reserva = :id FOR UPDATE"
            );
            $lockResumen->execute([':id' => $idReserva]);
            $row = $lockResumen->fetch(PDO::FETCH_ASSOC);

            $resumen = null;
            if ($row) {
                $resumen = $this->resumenModel->getByReserva($idReserva);
            } else {
                $resumen = $this->generarResumen($reserva);
            }

            if ($resumen === null) {
                throw new Exception("No se pudo generar el resumen de pago.");
            }

            if ($resumen->getSaldoPendiente() <= 0) {
                throw new Exception("La reserva ya se encuentra totalmente pagada.");
            }

            if ($montoAbonado > $resumen->getSaldoPendiente()) {
                throw new Exception(
                    "El monto ingresado supera el saldo pendiente de $" .
                    number_format($resumen->getSaldoPendiente(), 2, ',', '.') . "."
                );
            }

            $transaccion = new TransaccionPago();
            $transaccion->setIdResumenPago($resumen->getId());
            $transaccion->setIdMetodoPago($idMetodoPago);
            $transaccion->setMontoAbonado($montoAbonado);
            $transaccion->setRegistradoPor($registradoPor);

            $saved = $this->transaccionModel->save($transaccion);
            if (!$saved) {
                throw new Exception("No se pudo registrar la transacción de pago.");
            }
            $idTransaccion = (int)$db->lastInsertId();

            $nuevoMontoPagado = $resumen->getMontoPagado() + $montoAbonado;
            $nuevoSaldo       = $resumen->getSaldoPendiente() - $montoAbonado;

            $nuevoEstado = ReglasPago::estadoTrasPago($nuevoSaldo, $nuevoMontoPagado);

            $resumen->setIdEstadoPago($nuevoEstado);
            $resumen->setMontoPagado($nuevoMontoPagado);
            $resumen->setSaldoPendiente($nuevoSaldo);
            $this->resumenModel->update($resumen);

            $mensaje = "Pago registrado exitosamente por $" . number_format($montoAbonado, 2, ',', '.') . ".";

            if ($nuevoSaldo <= 0 && $estadoReserva === Reserva::ESTADO_PENDIENTE) {
                $this->reservaModel->cambiarEstado(
                    $idReserva,
                    Reserva::ESTADO_CONFIRMADA,
                    Reserva::ESTADO_PENDIENTE
                );
                $mensaje = "Pago registrado y reserva confirmada automáticamente por $" .
                    number_format($montoAbonado, 2, ',', '.') . ".";
            }

            $this->auditoria->registrar('pago.registrar', 'reserva', $idReserva, 'Pago registrado por $' . number_format($montoAbonado, 2, ',', '.'), $antes);
            // Recibo numerado en la misma transacción (con el saldo ya actualizado)
            (new ComprobanteService())->emitirDeTransaccion($idTransaccion, $registradoPor);
            (new MailService())->encolarComprobantePago($idReserva, $idTransaccion);
            $db->commit();
            return ['success' => true, 'message' => $mensaje];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Recalcula monto total y saldo del resumen de pago de una reserva.
     * Si ya hay una transacción abierta (ej. ReservaService::editar), se suma a ella
     * y deja el commit/rollback a quien la abrió.
     * Si se pasa $precioNocheFijo (ej. el precio pactado), se usa ese precio por noche
     * en lugar de recalcularlo con el precio base actual de la habitación.
     */
    public function recalcularResumen(array $reserva, ?float $precioNocheFijo = null): bool
    {
        $resumen = $this->resumenModel->getByReserva((int)$reserva['id']);
        if ($resumen === null) {
            return false;
        }

        $db = $this->resumenModel->getConnection();
        $transaccionPropia = !$db->inTransaction();
        if ($transaccionPropia) {
            $db->beginTransaction();
        }

        try {
            $lock = $db->prepare(
                "SELECT id, monto_total, monto_cobrado, saldo_pendiente
                 FROM Resumen_Pago WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $resumen->getId()]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                if ($transaccionPropia) {
                    $db->rollBack();
                }
                return false;
            }

            $montoTotalAnt = (float)$row['monto_total'];
            $montoCobrado  = (float)$row['monto_cobrado'];

            $noches     = Tarifa::noches($reserva['fecha_entrada'], $reserva['fecha_salida']);
            $precioBase = (float)$reserva['precio_noche_base'];
            $idTipo     = (int)$reserva['id_tipo_habitacion'];
            $huespedes  = (int)$reserva['cantidad_huespedes'];
            $precioEfectivo = $precioNocheFijo
                ?? Habitacion::precioEfectivoNoche($precioBase, $idTipo, $huespedes);
            $nuevoTotal = Tarifa::total($precioEfectivo, $noches);

            if ($nuevoTotal <= 0) {
                throw new Exception("No se pudo calcular un total válido para la reserva.");
            }

            $nuevoSaldo = $nuevoTotal - $montoCobrado;

            if ($nuevoSaldo < 0) {
                throw new Exception(
                    "El nuevo total ($" . number_format($nuevoTotal, 2, ',', '.') .
                    ") es menor a lo ya cobrado ($" . number_format($montoCobrado, 2, ',', '.') .
                    "). No se puede recalcular la reserva."
                );
            }

            $nuevoEstado = ReglasPago::estadoTrasRecalculo($nuevoSaldo, $montoCobrado, $resumen->getIdEstadoPago());

            $update = $db->prepare(
                "UPDATE Resumen_Pago
                 SET monto_total = :total, saldo_pendiente = :saldo,
                     id_estado_pago = :estado
                 WHERE id = :id"
            );
            $update->execute([
                ':total'  => $nuevoTotal,
                ':saldo'  => $nuevoSaldo,
                ':estado' => $nuevoEstado,
                ':id'     => $resumen->getId()
            ]);

            $this->reservaModel->actualizarPrecioPactado((int)$reserva['id'], $precioEfectivo);

            if ($montoTotalAnt !== $nuevoTotal) {
                $updateObs = $db->prepare(
                    "UPDATE Reservas
                     SET observaciones = CONCAT(
                         COALESCE(observaciones, ''), " . Reserva::SQL_SEPARADOR_OBS . ",
                         'Recálculo automático de precio: $',
                         :anterior, ' → $', :nuevo, '. Fecha: ', NOW()
                     )
                     WHERE id = :id"
                );
                $updateObs->execute([
                    ':anterior' => number_format($montoTotalAnt, 2, ',', '.'),
                    ':nuevo'    => number_format($nuevoTotal, 2, ',', '.'),
                    ':id'       => (int)$reserva['id']
                ]);
            }

            if ($transaccionPropia) {
                $db->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($transaccionPropia && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Deja asentado cuánto de lo cobrado retiene el hotel al cancelar o liberar una reserva.
     * monto_retenido = lo retenido (tope: lo cobrado); monto_total = lo cobrado y saldo 0,
     * porque ya no se cobra nada más (chk_resumen_balance: total = cobrado + saldo).
     * Si se cobró más de lo retenido, el pago queda A Reembolsar por la diferencia; si no, Retenido.
     * Sin cobros no modifica nada. Debe llamarse dentro de la transacción de quien cancela.
     * Misma lógica en los EVENTs corte_pagos y liberacion_no_show.
     *
     * @return int|null Estado de pago resultante, o null si no había cobros.
     */
    public function aplicarRetencion(int $idReserva, float $montoRetenido): ?int
    {
        $db = $this->resumenModel->getConnection();
        $lock = $db->prepare(
            "SELECT id, monto_cobrado FROM Resumen_Pago WHERE id_reserva = :id FOR UPDATE"
        );
        $lock->execute([':id' => $idReserva]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        $retencion = $row ? ReglasPago::retencion((float)$row['monto_cobrado'], $montoRetenido) : null;
        if ($retencion === null) {
            return null;
        }
        ['retenido' => $retenido, 'estado' => $estado] = $retencion;

        $upd = $db->prepare(
            "UPDATE Resumen_Pago
             SET monto_total = monto_cobrado, saldo_pendiente = 0,
                 monto_retenido = :retenido, id_estado_pago = :estado
             WHERE id = :id"
        );
        $upd->execute([':retenido' => $retenido, ':estado' => $estado, ':id' => (int)$row['id']]);

        return $estado;
    }

    /**
     * Aplica el cargo por late checkout a las reservas EN_CASA cuya salida es hoy
     * y que siguen sin checkout después de la hora de checkout (Configuracion.hora_checkout).
     * El cargo es factor_late_checkout (vigente a la fecha_alta de la reserva) × precio pactado de la noche (o el precio base si la reserva no lo tiene).
     * Lo ejecuta cron/late-checkout-fee.php.
     *
     * Idempotencia: columna Reservas.late_fee_aplicado. Se marca primero la reserva
     * (solo si seguía en 0) y recién entonces se suma el cargo, así no se cobra dos veces
     * aunque el EVENT cargo_late_checkout corra al mismo tiempo. El texto
     * "Late Checkout Fee Aplicado" en observaciones se mantiene (lo usa el EVENT y los datos viejos).
     */
    public function aplicarLateCheckout(): array
    {
        $db = $this->resumenModel->getConnection();

        $stmt = $db->prepare("
            SELECT r.id, r.id_habitacion, r.fecha_salida, r.fecha_alta, rp.id AS id_resumen,
                   rp.monto_cobrado, rp.saldo_pendiente, rp.monto_total,
                   COALESCE(r.precio_noche_pactado, h.precio_noche_base) AS precio_noche
            FROM Reservas r
            JOIN Resumen_Pago rp ON rp.id_reserva = r.id
            JOIN Habitaciones h ON h.id = r.id_habitacion
            WHERE r.id_estado_reserva = :encasa
              AND r.fecha_salida = :fecha_hoy
              AND :hora_actual >= :hora_checkout
              AND r.late_fee_aplicado = 0
              AND (r.observaciones IS NULL OR r.observaciones NOT LIKE '%Late Checkout Fee Aplicado%')
        ");
        $stmt->execute([
            ':encasa'        => Reserva::ESTADO_EN_CASA,
            ':fecha_hoy'     => date('Y-m-d'),
            ':hora_actual'   => date('H:i:s'),
            ':hora_checkout' => ConfiguracionService::horaCheckoutSql(),
        ]);

        $conLateCheckout = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $contador = 0;
        foreach ($conLateCheckout as $row) {
            $db->beginTransaction();
            try {
                $idReserva   = (int)$row['id'];
                $idResumen   = (int)$row['id_resumen'];
                $precioNoche = (float)$row['precio_noche'];
                $feeLateCheckout = ReglasPago::lateFee($precioNoche, ConfiguracionService::factorLateCheckout($row['fecha_alta']));

                if ($feeLateCheckout <= 0) {
                    $db->rollBack();
                    continue;
                }

                $updReserva = $db->prepare(
                    "UPDATE Reservas
                     SET late_fee_aplicado = 1,
                         observaciones = CONCAT(
                             COALESCE(observaciones, ''), " . Reserva::SQL_SEPARADOR_OBS . ",
                             'Late Checkout Fee Aplicado: $', :fee, :detalle
                         )
                     WHERE id = :id AND late_fee_aplicado = 0"
                );
                $updReserva->execute([
                    ':fee'     => number_format($feeLateCheckout, 2, ',', '.'),
                    ':detalle' => ' por salida despues de las ' . ConfiguracionService::horaCheckout() . ' hs.',
                    ':id'      => $idReserva
                ]);

                if ($updReserva->rowCount() === 0) {
                    // Ya lo aplicó otro proceso (EVENT o cron) entre el SELECT y este UPDATE.
                    $db->rollBack();
                    continue;
                }

                $updResumen = $db->prepare(
                    "UPDATE Resumen_Pago
                     SET monto_total = monto_total + :fee_total,
                         saldo_pendiente = saldo_pendiente + :fee_saldo
                     WHERE id = :id"
                );
                $updResumen->execute([
                    ':fee_total' => $feeLateCheckout,
                    ':fee_saldo' => $feeLateCheckout,
                    ':id'        => $idResumen
                ]);

                $db->commit();
                $contador++;
                error_log("[SIRES CRON] Reserva #" . $idReserva .
                    ": Late Checkout fee de $" . number_format($feeLateCheckout, 2, ',', '.') . " aplicado.");
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
            'message'    => 'Verificacion de Late Checkout completada. ' . $contador . ' cargo(s) aplicado(s).',
        ];
    }

    public function ejecutarCortePagos(): array
    {
        $horasCorte  = ConfiguracionService::horasCortePago();
        $fechaLimite = date('Y-m-d', time() + $horasCorte * 3600);
        $db = $this->resumenModel->getConnection();

        $stmt = $db->prepare("
            SELECT r.id, r.id_habitacion, r.fecha_entrada, rp.id_estado_pago,
                   rp.monto_cobrado, rp.monto_total, rp.id AS id_resumen
            FROM Reservas r
            JOIN Resumen_Pago rp ON rp.id_reserva = r.id
            WHERE rp.id_estado_pago <> :pagado_total
              AND (
                    (r.fecha_entrada = :fecha AND r.id_estado_reserva IN (:pendiente, :confirmada))
                 OR (r.fecha_entrada < :hoy AND r.id_estado_reserva = :pendiente_vencida)
              )
        ");
        $stmt->execute([
            ':fecha'             => $fechaLimite,
            ':hoy'               => date('Y-m-d'),
            ':pendiente'         => Reserva::ESTADO_PENDIENTE,
            ':confirmada'        => Reserva::ESTADO_CONFIRMADA,
            ':pendiente_vencida' => Reserva::ESTADO_PENDIENTE,
            ':pagado_total'      => ResumenPago::ESTADO_PAGADO_TOTAL
        ]);
        $afectadas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $contador = 0;
        foreach ($afectadas as $row) {
            $db->beginTransaction();
            try {
                $idReserva  = (int)$row['id'];

                // La seña no se reembolsa: el huésped no completó el pago a tiempo.
                $this->aplicarRetencion($idReserva, (float)$row['monto_cobrado']);

                $motivo = (string)$row['fecha_entrada'] < date('Y-m-d')
                    ? 'Cancelada automática: reserva pendiente con la fecha de entrada vencida.'
                    : 'Cancelada automática ' . $horasCorte . 'hs antes del check-in por falta de pago total.';

                $updateReserva = $db->prepare(
                    "UPDATE Reservas SET id_estado_reserva = :cancelada,
                         observaciones = CONCAT(COALESCE(observaciones, ''), " . Reserva::SQL_SEPARADOR_OBS . ", :obs)
                     WHERE id = :id"
                );
                $updateReserva->execute([
                    ':cancelada' => Reserva::ESTADO_CANCELADA,
                    ':obs'       => $motivo . ((float)$row['monto_cobrado'] > 0 ? ' Seña retenida.' : ''),
                    ':id'        => $idReserva
                ]);

                // No se modifica el estado de la habitación: la reserva cancelada es futura
                // (check-in en N horas) y la habitación puede estar ocupada hoy por otro huésped.
                // La disponibilidad para esas fechas se libera sola al quedar la reserva Cancelada.

                $db->commit();
                $contador++;
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("Error en corte de pagos para reserva #" . $row['id'] . ": " . $e->getMessage());
            }
        }

        return [
            'success'    => true,
            'procesadas' => $contador,
            'message'    => 'Corte de pagos ejecutado. ' . $contador . ' reserva(s) afectada(s).',
        ];
    }

    public function recalcularManual(int $idReserva): array
    {
        $reserva = $this->reservaModel->findById($idReserva);
        if (!$reserva) {
            throw new Exception("La reserva no existe.");
        }

        $db = $this->resumenModel->getConnection();
        $db->beginTransaction();
        $antes = $this->auditoria->foto('reserva', $idReserva);

        try {
            $lock = $db->prepare(
                "SELECT rp.id, rp.monto_cobrado, rp.monto_total, rp.saldo_pendiente,
                        h.precio_noche_base, h.id_tipo_habitacion,
                        r.cantidad_huespedes
                 FROM Resumen_Pago rp
                 JOIN Reservas r ON r.id = rp.id_reserva
                 JOIN Habitaciones h ON h.id = r.id_habitacion
                 WHERE rp.id_reserva = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idReserva]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception("La reserva no tiene un resumen de pago.");
            }

            $montoCobrado  = (float)$row['monto_cobrado'];
            $montoTotalAnt = (float)$row['monto_total'];
            $precioNoche   = (float)$row['precio_noche_base'];
            $idTipo        = (int)$row['id_tipo_habitacion'];
            $huespedes     = (int)$row['cantidad_huespedes'];

            $noches  = Tarifa::noches($reserva['fecha_entrada'], $reserva['fecha_salida']);

            $precioEfectivo = Habitacion::precioEfectivoNoche($precioNoche, $idTipo, $huespedes);
            $nuevoTotal = Tarifa::total($precioEfectivo, $noches);

            if ($nuevoTotal <= 0) {
                throw new Exception("No se pudo calcular un total válido.");
            }

            if ($montoCobrado > 0 && $nuevoTotal < $montoCobrado) {
                throw new Exception(
                    "El nuevo total ($" . number_format($nuevoTotal, 2, ',', '.') .
                    ") es menor a lo ya cobrado ($" . number_format($montoCobrado, 2, ',', '.') .
                    "). No se puede recalcular."
                );
            }

            $nuevoSaldo = $nuevoTotal - $montoCobrado;

            $nuevoEstado = ReglasPago::estadoTrasRecalculo($nuevoSaldo, $montoCobrado);

            $update = $db->prepare(
                "UPDATE Resumen_Pago
                 SET monto_total = :total, saldo_pendiente = :saldo,
                     id_estado_pago = :estado
                 WHERE id = :id"
            );
            $update->execute([
                ':total'  => $nuevoTotal,
                ':saldo'  => $nuevoSaldo,
                ':estado' => $nuevoEstado,
                ':id'     => (int)$row['id']
            ]);

            $this->reservaModel->actualizarPrecioPactado($idReserva, $precioEfectivo);

            $updateObs = $db->prepare(
                "UPDATE Reservas
                 SET observaciones = CONCAT(
                     COALESCE(observaciones, ''), " . Reserva::SQL_SEPARADOR_OBS . ",
                     'Recálculo manual de precio: $',
                     :anterior, ' → $', :nuevo, '. Fecha: ', NOW()
                 )
                 WHERE id = :id"
            );
            $updateObs->execute([
                ':anterior' => number_format($montoTotalAnt, 2, ',', '.'),
                ':nuevo'    => number_format($nuevoTotal, 2, ',', '.'),
                ':id'       => $idReserva
            ]);

            $this->auditoria->registrar('pago.recalcular', 'reserva', $idReserva, 'Recálculo del precio', $antes);
            $db->commit();
            return [
                'success' => true,
                'message' => 'Precio recalculado exitosamente. Total anterior: $' .
                    number_format($montoTotalAnt, 2, ',', '.') .
                    ' → Nuevo total: $' . number_format($nuevoTotal, 2, ',', '.') . '.'
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function procesarReembolso(int $idReserva): array
    {
        $resumen = $this->resumenModel->getByReserva($idReserva);
        if ($resumen === null) {
            throw new Exception("La reserva no tiene un resumen de pago asociado.");
        }
        if ($resumen->getIdEstadoPago() !== EstadoPago::A_REEMBOLSAB) {
            throw new Exception("La reserva no está pendiente de reembolso.");
        }

        $montoReembolso = $resumen->getMontoAReembolsar();
        if ($montoReembolso <= 0) {
            throw new Exception("No hay monto cobrado para reembolsar.");
        }
        $montoRetenido = $resumen->getMontoRetenido();

        $db = $this->resumenModel->getConnection();
        $db->beginTransaction();
        $antes = $this->auditoria->foto('reserva', $idReserva);

        try {
            $lock = $db->prepare(
                "SELECT id, monto_cobrado, id_estado_pago FROM Resumen_Pago WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $resumen->getId()]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception("El resumen de pago no existe.");
            }
            // Con la fila bloqueada: otro pedido simultáneo pudo haber reembolsado entre la lectura y el bloqueo
            if ((int)$row['id_estado_pago'] !== EstadoPago::A_REEMBOLSAB) {
                throw new Exception("La reserva no está pendiente de reembolso.");
            }

            $insertTx = $db->prepare(
                "INSERT INTO Transacciones_Pago (id_resumen_pago, id_metodo_pago, monto_abonado, registrado_por)
                 VALUES (:id_resumen, :id_metodo, :monto, :registrado)"
            );
            $insertTx->execute([
                ':id_resumen' => $resumen->getId(),
                ':id_metodo'  => 1,
                ':monto'      => -$montoReembolso,
                ':registrado' => $_SESSION['user_id'] ?? 1
            ]);
            $idTransaccion = (int)$db->lastInsertId();

            $update = $db->prepare(
                "UPDATE Resumen_Pago
                 SET id_estado_pago = :estado,
                     monto_total    = :total,
                     monto_cobrado  = :cobrado,
                     saldo_pendiente = :saldo
                 WHERE id = :id"
            );
            $update->execute([
                ':estado'  => ReglasPago::estadoTrasReembolso($montoRetenido),
                ':total'   => $montoRetenido,
                ':cobrado' => $montoRetenido,
                ':saldo'   => 0.0,
                ':id'      => $resumen->getId()
            ]);

            $this->auditoria->registrar('pago.reembolso', 'reserva', $idReserva, 'Reembolso', $antes);
            (new ComprobanteService())->emitirDeTransaccion($idTransaccion, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
            $db->commit();
            return [
                'success' => true,
                'message' => 'Reembolso procesado exitosamente por $' .
                    number_format($montoReembolso, 2, ',', '.') . '.' .
                    ($montoRetenido > 0 ? ' Retenido por el hotel: $' . number_format($montoRetenido, 2, ',', '.') . '.' : '')
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}