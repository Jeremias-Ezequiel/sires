<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use App\Models\Reserva;
use App\Models\ResumenPago;
use App\Models\EstadoPago;
use App\Models\TransaccionPago;
use App\Models\Habitacion;
use PDO;
use PDOException;

class PagoService
{
    private ResumenPago $resumenModel;
    private TransaccionPago $transaccionModel;
    private Reserva $reservaModel;

    public function __construct()
    {
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

        $entrada = new \DateTime($reserva['fecha_entrada']);
        $salida  = new \DateTime($reserva['fecha_salida']);

        if ($salida <= $entrada) {
            throw new Exception("La reserva no tiene fechas válidas para calcular el total.");
        }

        $noches     = $entrada->diff($salida)->days;
        $precioBase = (float)$reserva['precio_noche_base'];
        $total      = $precioBase * $noches;

        if ($total <= 0) {
            throw new Exception("No se pudo calcular un total válido para la reserva.");
        }

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
        if ((int)$reserva['id_estado_reserva'] === Reserva::ESTADO_CANCELADA) {
            throw new Exception("No se pueden registrar pagos sobre una reserva cancelada.");
        }

        $db = $this->resumenModel->getConnection();
        $db->beginTransaction();

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
                $db->rollBack();
                throw new Exception("No se pudo generar el resumen de pago.");
            }

            if ($resumen->getSaldoPendiente() <= 0) {
                $db->rollBack();
                throw new Exception("La reserva ya se encuentra totalmente pagada.");
            }

            if ($montoAbonado > $resumen->getSaldoPendiente()) {
                $db->rollBack();
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
                $db->rollBack();
                throw new Exception("No se pudo registrar la transacción de pago.");
            }

            $nuevoMontoPagado = $resumen->getMontoPagado() + $montoAbonado;
            $nuevoSaldo       = $resumen->getSaldoPendiente() - $montoAbonado;

            $nuevoEstado = ResumenPago::ESTADO_PENDIENTE;
            if ($nuevoSaldo <= 0) {
                $nuevoEstado = ResumenPago::ESTADO_PAGADO_TOTAL;
            } elseif ($nuevoMontoPagado > 0) {
                $nuevoEstado = ResumenPago::ESTADO_PAGO_PARCIAL;
            }

            $resumen->setIdEstadoPago($nuevoEstado);
            $resumen->setMontoPagado($nuevoMontoPagado);
            $resumen->setSaldoPendiente($nuevoSaldo);
            $this->resumenModel->update($resumen);

            $estadoReserva = (int)$reserva['id_estado_reserva'];
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

            $db->commit();
            return ['success' => true, 'message' => $mensaje];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function recalcularResumen(array $reserva): bool
    {
        $resumen = $this->resumenModel->getByReserva((int)$reserva['id']);
        if ($resumen === null) {
            return false;
        }

        $db = $this->resumenModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id, monto_total, monto_cobrado, saldo_pendiente
                 FROM Resumen_Pago WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $resumen->getId()]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $db->rollBack();
                return false;
            }

            $montoTotalAnt = (float)$row['monto_total'];
            $montoCobrado  = (float)$row['monto_cobrado'];

            $entrada    = new \DateTime($reserva['fecha_entrada']);
            $salida     = new \DateTime($reserva['fecha_salida']);
            $noches     = $entrada->diff($salida)->days;
            $precioBase = (float)$reserva['precio_noche_base'];
            $nuevoTotal = $precioBase * $noches;

            if ($nuevoTotal <= 0) {
                $db->rollBack();
                throw new Exception("No se pudo calcular un total válido para la reserva.");
            }

            $nuevoSaldo = $nuevoTotal - $montoCobrado;

            if ($nuevoSaldo < 0) {
                $db->rollBack();
                throw new Exception(
                    "El nuevo total ($" . number_format($nuevoTotal, 2, ',', '.') .
                    ") es menor a lo ya cobrado ($" . number_format($montoCobrado, 2, ',', '.') .
                    "). No se puede recalcular la reserva."
                );
            }

            $nuevoEstado = ResumenPago::ESTADO_PENDIENTE;
            if ($nuevoSaldo <= 0 && $montoCobrado > 0) {
                $nuevoEstado = ResumenPago::ESTADO_PAGADO_TOTAL;
            } elseif ($montoCobrado > 0) {
                $nuevoEstado = ResumenPago::ESTADO_PAGO_PARCIAL;
            }

            if ($resumen->getIdEstadoPago() === ResumenPago::ESTADO_REEMBOLSADO) {
                $nuevoEstado = ResumenPago::ESTADO_REEMBOLSADO;
            }

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

            if ($montoTotalAnt !== $nuevoTotal) {
                $updateObs = $db->prepare(
                    "UPDATE Reservas
                     SET observaciones = CONCAT(
                         COALESCE(observaciones, ''),
                         ' | Recálculo automático de precio: $',
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

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function ejecutarCortePagos(): array
    {
        $fechaLimite = date('Y-m-d', time() + 48 * 3600);
        $db = $this->resumenModel->getConnection();

        $stmt = $db->prepare("
            SELECT r.id, r.id_habitacion, rp.id_estado_pago,
                   rp.monto_cobrado, rp.monto_total, rp.id AS id_resumen
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

        $contador = 0;
        foreach ($afectadas as $row) {
            $db->beginTransaction();
            try {
                $idReserva  = (int)$row['id'];
                $idResumen  = (int)$row['id_resumen'];
                $estadoPago = (int)$row['id_estado_pago'];

                if ($estadoPago === ResumenPago::ESTADO_PAGO_PARCIAL) {
                    $updateResumen = $db->prepare(
                        "UPDATE Resumen_Pago SET id_estado_pago = :estado
                         WHERE id = :id"
                    );
                    $updateResumen->execute([
                        ':estado' => EstadoPago::A_REEMBOLSAB,
                        ':id'     => $idResumen
                    ]);
                }

                $updateReserva = $db->prepare(
                    "UPDATE Reservas SET id_estado_reserva = :cancelada,
                         observaciones = CONCAT(COALESCE(observaciones, ''), ' | Cancelada automática 48hs antes del check-in por falta de pago total.')
                     WHERE id = :id"
                );
                $updateReserva->execute([
                    ':cancelada' => Reserva::ESTADO_CANCELADA,
                    ':id'        => $idReserva
                ]);

                $updateHabitacion = $db->prepare(
                    "UPDATE Habitaciones SET id_estado_habitacion = :disponible
                     WHERE id = :id"
                );
                $updateHabitacion->execute([
                    ':disponible' => Habitacion::ESTADO_DISPONIBLE,
                    ':id'         => (int)$row['id_habitacion']
                ]);

                $db->commit();
                $contador++;
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Error en corte de pagos para reserva #" . $row['id'] . ": " . $e->getMessage());
            }
        }

        return ['success' => true, 'message' => 'Corte de pagos ejecutado. ' . $contador . ' reserva(s) afectada(s).'];
    }

    public function recalcularManual(int $idReserva): array
    {
        $reserva = $this->reservaModel->findById($idReserva);
        if (!$reserva) {
            throw new Exception("La reserva no existe.");
        }

        $db = $this->resumenModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT rp.id, rp.monto_cobrado, rp.monto_total, rp.saldo_pendiente,
                        h.precio_noche_base
                 FROM Resumen_Pago rp
                 JOIN Reservas r ON r.id = rp.id_reserva
                 JOIN Habitaciones h ON h.id = r.id_habitacion
                 WHERE rp.id_reserva = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idReserva]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $db->rollBack();
                throw new Exception("La reserva no tiene un resumen de pago.");
            }

            $montoCobrado  = (float)$row['monto_cobrado'];
            $montoTotalAnt = (float)$row['monto_total'];
            $precioNoche   = (float)$row['precio_noche_base'];

            $entrada = new \DateTime($reserva['fecha_entrada']);
            $salida  = new \DateTime($reserva['fecha_salida']);
            $noches  = $entrada->diff($salida)->days;

            $nuevoTotal = $precioNoche * $noches;

            if ($nuevoTotal <= 0) {
                $db->rollBack();
                throw new Exception("No se pudo calcular un total válido.");
            }

            if ($montoCobrado > 0 && $nuevoTotal < $montoCobrado) {
                $db->rollBack();
                throw new Exception(
                    "El nuevo total ($" . number_format($nuevoTotal, 2, ',', '.') .
                    ") es menor a lo ya cobrado ($" . number_format($montoCobrado, 2, ',', '.') .
                    "). No se puede recalcular."
                );
            }

            $nuevoSaldo = $nuevoTotal - $montoCobrado;

            $nuevoEstado = ResumenPago::ESTADO_PENDIENTE;
            if ($nuevoSaldo <= 0 && $montoCobrado > 0) {
                $nuevoEstado = ResumenPago::ESTADO_PAGADO_TOTAL;
            } elseif ($montoCobrado > 0) {
                $nuevoEstado = ResumenPago::ESTADO_PAGO_PARCIAL;
            }

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

            $updateObs = $db->prepare(
                "UPDATE Reservas
                 SET observaciones = CONCAT(
                     COALESCE(observaciones, ''),
                     ' | Recálculo manual de precio: $',
                     :anterior, ' → $', :nuevo, '. Fecha: ', NOW()
                 )
                 WHERE id = :id"
            );
            $updateObs->execute([
                ':anterior' => number_format($montoTotalAnt, 2, ',', '.'),
                ':nuevo'    => number_format($nuevoTotal, 2, ',', '.'),
                ':id'       => $idReserva
            ]);

            $db->commit();
            return [
                'success' => true,
                'message' => 'Precio recalculado exitosamente. Total anterior: $' .
                    number_format($montoTotalAnt, 2, ',', '.') .
                    ' → Nuevo total: $' . number_format($nuevoTotal, 2, ',', '.') . '.'
            ];
        } catch (Exception $e) {
            $db->rollBack();
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

        $montoReembolso = $resumen->getMontoPagado();
        if ($montoReembolso <= 0) {
            throw new Exception("No hay monto cobrado para reembolsar.");
        }

        $db = $this->resumenModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id, monto_cobrado FROM Resumen_Pago WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $resumen->getId()]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $db->rollBack();
                throw new Exception("El resumen de pago no existe.");
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

            $update = $db->prepare(
                "UPDATE Resumen_Pago
                 SET id_estado_pago = :estado,
                     monto_total    = :total,
                     monto_cobrado  = :cobrado,
                     saldo_pendiente = :saldo
                 WHERE id = :id"
            );
            $update->execute([
                ':estado'  => ResumenPago::ESTADO_REEMBOLSADO,
                ':total'   => 0.0,
                ':cobrado' => 0.0,
                ':saldo'   => 0.0,
                ':id'      => $resumen->getId()
            ]);

            $db->commit();
            return [
                'success' => true,
                'message' => 'Reembolso procesado exitosamente por $' .
                    number_format($montoReembolso, 2, ',', '.') . '.'
            ];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}