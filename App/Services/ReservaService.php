<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use App\Models\Reserva;
use App\Models\Habitacion;
use App\Models\ResumenPago;
use App\Models\TransaccionPago;
use PDO;
use PDOException;

class ReservaService
{
    private Reserva $reservaModel;
    private Habitacion $habitacionModel;
    private ResumenPago $resumenModel;

    public function __construct()
    {
        $this->reservaModel   = new Reserva();
        $this->habitacionModel = new Habitacion();
        $this->resumenModel   = new ResumenPago();
    }

    public function crear(array $datos): array
    {
        $idHabitacion   = (int)$datos['id_habitacion'];
        $fechaEntrada   = trim($datos['fecha_entrada'] ?? '');
        $fechaSalida    = trim($datos['fecha_salida'] ?? '');
        $cantHuespedes  = (int)$datos['cantidad_huespedes'];

        if (empty($fechaEntrada) || empty($fechaSalida)) {
            throw new Exception("Las fechas de entrada y salida son obligatorias.");
        }
        if ($fechaSalida <= $fechaEntrada) {
            throw new Exception("La fecha de salida debe ser posterior a la fecha de entrada.");
        }

        $habitacionRow = $this->habitacionModel->findById($idHabitacion);
        if (!$habitacionRow) {
            throw new Exception("La habitación seleccionada no existe.");
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idHabitacion]);
            $estadoActual = (int)$lock->fetchColumn();

            if ($estadoActual === Habitacion::ESTADO_MANTENIMIENTO
                || $estadoActual === Habitacion::ESTADO_BLOQUEADA
                || $estadoActual === Habitacion::ESTADO_SUCIA
                || $estadoActual === Habitacion::ESTADO_LIMPIANDO) {
                throw new Exception("La habitación seleccionada no está disponible.");
            }

            if ($this->reservaModel->existeSolapamiento($idHabitacion, $fechaEntrada, $fechaSalida)) {
                throw new Exception("La habitación ya tiene una reserva pendiente o confirmada para ese rango de fechas.");
            }

            $reserva = new Reserva();
            $reserva->setIdCliente((int)$datos['id_cliente']);
            $reserva->setIdHabitacion($idHabitacion);
            $reserva->setIdCanalOrigen((int)$datos['id_canal_origen']);
            $reserva->setFechaEntrada($fechaEntrada);
            $reserva->setFechaSalida($fechaSalida);
            $reserva->setCantidadHuespedes($cantHuespedes);
            $reserva->setObservaciones($datos['observaciones'] ?? null);
            $reserva->setCreadoPor((int)$datos['creado_por']);

            $ok = $this->reservaModel->save($reserva);
            if (!$ok) {
                throw new Exception("No se pudo registrar la reserva.");
            }

            $idStmt = $db->prepare("SELECT LAST_INSERT_ID()");
            $idStmt->execute();
            $nuevoIdReserva = (int)$idStmt->fetchColumn();

            $entrada = new \DateTime($fechaEntrada);
            $salida  = new \DateTime($fechaSalida);
            $noches  = $entrada->diff($salida)->days;
            $precioBase = (float)$habitacionRow['precio_noche_base'];
            $total   = $precioBase * $noches;

            $insertResumen = $db->prepare(
                "INSERT INTO Resumen_Pago (id_reserva, id_estado_pago, monto_total, monto_cobrado, saldo_pendiente)
                 VALUES (:id_reserva, :estado, :total, 0, :saldo)"
            );
            $insertResumen->execute([
                ':id_reserva' => $nuevoIdReserva,
                ':estado'     => ResumenPago::ESTADO_PENDIENTE,
                ':total'      => $total,
                ':saldo'      => $total
            ]);

            $db->commit();
            return ['success' => true, 'message' => 'Reserva registrada exitosamente.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function confirmar(int $idReserva): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }
        if ((int)$reservaData['id_estado_reserva'] !== Reserva::ESTADO_PENDIENTE) {
            throw new Exception("Solo se pueden confirmar reservas pendientes.");
        }

        $resumen = $this->resumenModel->getByReserva($idReserva);
        if ($resumen === null || $resumen->getMontoPagado() <= 0) {
            throw new Exception("Debe realizar al menos un pago para confirmar la reserva.");
        }

        $ok = $this->reservaModel->cambiarEstado($idReserva, Reserva::ESTADO_CONFIRMADA, Reserva::ESTADO_PENDIENTE);
        if (!$ok) {
            throw new Exception("No se pudo confirmar la reserva.");
        }

        return ['success' => true, 'message' => 'Reserva confirmada exitosamente.'];
    }

    public function checkIn(int $idReserva): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }
        if ((int)$reservaData['id_estado_reserva'] !== Reserva::ESTADO_CONFIRMADA) {
            throw new Exception("Solo se puede hacer check-in de reservas confirmadas.");
        }

        $resumen = $this->resumenModel->getByReserva($idReserva);
        if ($resumen === null || $resumen->getIdEstadoPago() !== ResumenPago::ESTADO_PAGADO_TOTAL) {
            throw new Exception("No se puede realizar el check-in. La estadía debe estar 100% pagada.");
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => (int)$reservaData['id_habitacion']]);
            $estadoHabitacion = (int)$lock->fetchColumn();

            if ($estadoHabitacion === Habitacion::ESTADO_OCUPADA) {
                $db->rollBack();
                throw new Exception("La habitación ya se encuentra ocupada.");
            }
            if ($estadoHabitacion !== Habitacion::ESTADO_DISPONIBLE) {
                $db->rollBack();
                throw new Exception("La habitación no está disponible para check-in.");
            }

            $roomOk = $this->habitacionModel->cambiarEstado(
                (int)$reservaData['id_habitacion'],
                Habitacion::ESTADO_OCUPADA,
                Habitacion::ESTADO_DISPONIBLE
            );
            if (!$roomOk) {
                $db->rollBack();
                throw new Exception("Error al ocupar la habitación.");
            }

            $reservaOk = $this->reservaModel->cambiarEstado(
                $idReserva,
                Reserva::ESTADO_EN_CASA,
                Reserva::ESTADO_CONFIRMADA
            );
            if (!$reservaOk) {
                $this->habitacionModel->cambiarEstado((int)$reservaData['id_habitacion'], Habitacion::ESTADO_DISPONIBLE);
                $db->rollBack();
                throw new Exception("Error al actualizar el estado de la reserva.");
            }

            $db->commit();
            return ['success' => true, 'message' => 'Check-in realizado exitosamente.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function checkOut(int $idReserva): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }

        $estadoActual = (int)$reservaData['id_estado_reserva'];
        if ($estadoActual !== Reserva::ESTADO_CONFIRMADA && $estadoActual !== Reserva::ESTADO_EN_CASA) {
            throw new Exception("No se puede finalizar una reserva que no está confirmada o en estadía.");
        }

        $resumen = $this->resumenModel->getByReserva($idReserva);
        if ($resumen === null || $resumen->getIdEstadoPago() !== ResumenPago::ESTADO_PAGADO_TOTAL) {
            throw new Exception("No se puede finalizar la reserva hasta que el pago esté completo.");
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $reservaOk = $this->reservaModel->cambiarEstado($idReserva, Reserva::ESTADO_FINALIZADA, $estadoActual);
            if (!$reservaOk) {
                throw new Exception("No se pudo finalizar la reserva.");
            }

            $roomOk = $this->habitacionModel->cambiarEstado(
                (int)$reservaData['id_habitacion'],
                Habitacion::ESTADO_SUCIA,
                Habitacion::ESTADO_OCUPADA
            );
            if (!$roomOk) {
                $this->reservaModel->cambiarEstado($idReserva, $estadoActual, Reserva::ESTADO_FINALIZADA);
                throw new Exception("Error al marcar la habitación como sucia.");
            }

            $db->commit();
            return ['success' => true, 'message' => 'Checkout realizado. La habitación pasa a limpieza.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function checkoutAnticipado(int $idReserva): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }
        if ((int)$reservaData['id_estado_reserva'] !== Reserva::ESTADO_EN_CASA) {
            throw new Exception("Solo se puede hacer checkout anticipado de reservas en estadía.");
        }

        $resumen = $this->resumenModel->getByReserva($idReserva);
        if ($resumen === null || $resumen->getIdEstadoPago() !== ResumenPago::ESTADO_PAGADO_TOTAL) {
            throw new Exception("No se puede finalizar la reserva hasta que el pago esté completo.");
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $reservaOk = $this->reservaModel->cambiarEstado(
                $idReserva,
                Reserva::ESTADO_FINALIZADA,
                Reserva::ESTADO_EN_CASA
            );
            if (!$reservaOk) {
                throw new Exception("No se pudo finalizar la reserva.");
            }

            $roomOk = $this->habitacionModel->cambiarEstado(
                (int)$reservaData['id_habitacion'],
                Habitacion::ESTADO_SUCIA,
                Habitacion::ESTADO_OCUPADA
            );
            if (!$roomOk) {
                $this->reservaModel->cambiarEstado($idReserva, Reserva::ESTADO_EN_CASA, Reserva::ESTADO_FINALIZADA);
                throw new Exception("Error al liberar la habitación.");
            }

            $db->commit();
            return ['success' => true, 'message' => 'Checkout anticipado realizado. Sin reembolso por días no usados.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function cancelar(int $idReserva): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }

        $estadoActual = (int)$reservaData['id_estado_reserva'];
        if ($estadoActual === Reserva::ESTADO_CANCELADA
            || $estadoActual === Reserva::ESTADO_FINALIZADA
            || $estadoActual === Reserva::ESTADO_EN_CASA) {
            throw new Exception("No se puede cancelar una reserva finalizada, en estadía o ya cancelada.");
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            if ($estadoActual === Reserva::ESTADO_PENDIENTE) {
                $ok = $this->reservaModel->cambiarEstado($idReserva, Reserva::ESTADO_CANCELADA, Reserva::ESTADO_PENDIENTE);
                if (!$ok) {
                    $db->rollBack();
                    throw new Exception("No se pudo cancelar la reserva.");
                }
            } else {
                $ok = $this->reservaModel->cambiarEstado($idReserva, Reserva::ESTADO_CANCELADA, Reserva::ESTADO_CONFIRMADA);
                if (!$ok) {
                    $db->rollBack();
                    throw new Exception("No se pudo cancelar la reserva.");
                }

                $resumen = $this->resumenModel->getByReserva($idReserva);
                if ($resumen !== null && $resumen->getMontoPagado() > 0) {
                    $this->resumenModel->reembolsarPorReserva($idReserva);
                }
            }

            $this->habitacionModel->cambiarEstado(
                (int)$reservaData['id_habitacion'],
                Habitacion::ESTADO_DISPONIBLE
            );

            $db->commit();
            return ['success' => true, 'message' => 'Reserva cancelada exitosamente.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
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

        $idHabitacion = (int)$reservaData['id_habitacion'];

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

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

                $this->habitacionModel->cambiarEstado(
                    $idHabitacion,
                    Habitacion::ESTADO_OCUPADA,
                    Habitacion::ESTADO_DISPONIBLE
                );

                $db->commit();
                return [
                    'success' => true,
                    'message' => 'No-Show registrado. El pago NO se reembolsa. La habitación queda retenida hasta el checkout del día siguiente.'
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

                $this->habitacionModel->cambiarEstado(
                    $idHabitacion,
                    Habitacion::ESTADO_DISPONIBLE
                );

                $db->commit();
                return ['success' => true, 'message' => 'No-Show registrado. Habitación liberada (sin pago).'];
            }
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function moverHabitacion(int $idReserva, int $idNuevaHabitacion): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }
        if ((int)$reservaData['id_estado_reserva'] !== Reserva::ESTADO_EN_CASA) {
            throw new Exception("Solo se puede mover una reserva en estadía activa.");
        }

        $nuevaHabitacion = $this->habitacionModel->findById($idNuevaHabitacion);
        if (!$nuevaHabitacion) {
            throw new Exception("La habitación destino no existe.");
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $lockDestino = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lockDestino->execute([':id' => $idNuevaHabitacion]);
            if ((int)$lockDestino->fetchColumn() !== Habitacion::ESTADO_DISPONIBLE) {
                $db->rollBack();
                throw new Exception("La habitación destino no está disponible.");
            }

            $idHabOriginal = (int)$reservaData['id_habitacion'];

            $roomNuevaOk = $this->habitacionModel->cambiarEstado(
                $idNuevaHabitacion,
                Habitacion::ESTADO_OCUPADA,
                Habitacion::ESTADO_DISPONIBLE
            );
            if (!$roomNuevaOk) {
                $db->rollBack();
                throw new Exception("Error al ocupar la habitación destino.");
            }

            $updateReserva = $db->prepare(
                "UPDATE Reservas SET id_habitacion = :nueva WHERE id = :id"
            );
            $updateReserva->execute([
                ':nueva' => $idNuevaHabitacion,
                ':id'    => $idReserva
            ]);

            $this->habitacionModel->cambiarEstado(
                $idHabOriginal,
                Habitacion::ESTADO_MANTENIMIENTO,
                Habitacion::ESTADO_OCUPADA
            );

            $db->commit();
            return [
                'success' => true,
                'message' => 'Huésped trasladado a habitación #' . $nuevaHabitacion['numero'] . '. La habitación original pasa a mantenimiento.'
            ];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function extenderEstadia(int $idReserva, string $nuevaFechaSalida): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }
        if ((int)$reservaData['id_estado_reserva'] !== Reserva::ESTADO_EN_CASA) {
            throw new Exception("Solo se puede extender una reserva en estadía activa.");
        }

        $fechaSalidaActual = $reservaData['fecha_salida'];
        if ($nuevaFechaSalida <= $fechaSalidaActual) {
            throw new Exception("La nueva fecha de salida debe ser posterior a la actual.");
        }

        $idHabitacion = (int)$reservaData['id_habitacion'];

        if ($this->reservaModel->existeSolapamiento($idHabitacion, $fechaSalidaActual, $nuevaFechaSalida, $idReserva)) {
            $habitacionesDisponibles = $this->habitacionModel->getAllWithFilters(null, '1', null, null);

            $disponible = null;
            foreach ($habitacionesDisponibles as $hab) {
                if ((int)$hab['id'] !== $idHabitacion
                    && !$this->reservaModel->existeSolapamiento(
                        (int)$hab['id'], $fechaSalidaActual, $nuevaFechaSalida
                    )) {
                    $disponible = $hab;
                    break;
                }
            }

            if ($disponible === null) {
                throw new Exception(
                    "La habitación actual está reservada para esas fechas y no hay otra habitación disponible para la extensión."
                );
            }

            return $this->crearSplitReservation($idReserva, (int)$disponible['id'], $fechaSalidaActual, $nuevaFechaSalida);
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $update = $db->prepare(
                "UPDATE Reservas SET fecha_salida = :nueva WHERE id = :id"
            );
            $update->execute([':nueva' => $nuevaFechaSalida, ':id' => $idReserva]);

            $reservaActualizada = $this->reservaModel->findById($idReserva);
            if ($reservaActualizada !== null) {
                $this->resumenModel->recalcular($reservaActualizada);
            }

            $db->commit();
            return ['success' => true, 'message' => 'Estadía extendida exitosamente.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private function crearSplitReservation(
        int $idReservaOriginal,
        int $idNuevaHabitacion,
        string $fechaInicioSplit,
        string $nuevaFechaSalida
    ): array {
        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $update = $db->prepare(
                "UPDATE Reservas SET fecha_salida = :nueva WHERE id = :id"
            );
            $update->execute([':nueva' => $fechaInicioSplit, ':id' => $idReservaOriginal]);

            $reservaOriginal = $this->reservaModel->findById($idReservaOriginal);

            $nuevaReserva = new Reserva();
            $nuevaReserva->setIdCliente((int)$reservaOriginal['id_cliente']);
            $nuevaReserva->setIdHabitacion($idNuevaHabitacion);
            $nuevaReserva->setIdCanalOrigen((int)$reservaOriginal['id_canal_origen']);
            $nuevaReserva->setFechaEntrada($fechaInicioSplit);
            $nuevaReserva->setFechaSalida($nuevaFechaSalida);
            $nuevaReserva->setCantidadHuespedes((int)$reservaOriginal['cantidad_huespedes']);
            $nuevaReserva->setObservaciones(
                "Extensión de reserva #" . $idReservaOriginal . ". Huésped se muda a esta habitación."
            );
            $nuevaReserva->setCreadoPor((int)$reservaOriginal['creado_por']);
            $this->reservaModel->save($nuevaReserva);

            $roomOk = $this->habitacionModel->cambiarEstado(
                $idNuevaHabitacion,
                Habitacion::ESTADO_OCUPADA,
                Habitacion::ESTADO_DISPONIBLE
            );
            if (!$roomOk) {
                $db->rollBack();
                throw new Exception("Error al ocupar la habitación de extensión.");
            }

            $db->commit();
            return [
                'success' => true,
                'message' => 'Estadía dividida. El huésped debe mudarse a la habitación #' .
                    $this->habitacionModel->findById($idNuevaHabitacion)['numero'] .
                    ' a partir del ' . $fechaInicioSplit . '.'
            ];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
