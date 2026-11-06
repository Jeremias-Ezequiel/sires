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

    /** Estados de habitación en los que no se puede asignar una reserva. */
    private const ESTADOS_HABITACION_NO_RESERVABLES = [
        Habitacion::ESTADO_MANTENIMIENTO,
        Habitacion::ESTADO_BLOQUEADA,
        Habitacion::ESTADO_SUCIA,
        Habitacion::ESTADO_LIMPIANDO,
    ];

    /**
     * Validaciones comunes a crear y editar: fechas, existencia de la habitación y capacidad.
     * Devuelve la fila de la habitación.
     */
    private function validarDatosBasicos(int $idHabitacion, string $fechaEntrada, string $fechaSalida, int $cantHuespedes): array
    {
        if ($fechaEntrada === '' || $fechaSalida === '') {
            throw new Exception("Las fechas de entrada y salida son obligatorias.");
        }
        if ($fechaSalida <= $fechaEntrada) {
            throw new Exception("La fecha de salida debe ser posterior a la fecha de entrada.");
        }

        $habitacionRow = $this->habitacionModel->findById($idHabitacion);
        if (!$habitacionRow) {
            throw new Exception("La habitación seleccionada no existe.");
        }

        $capacidadMax = Habitacion::capacidadParaTipo((int)$habitacionRow['id_tipo_habitacion']);
        if ($cantHuespedes > $capacidadMax) {
            throw new Exception(
                "La cantidad de huéspedes (" . $cantHuespedes .
                ") supera la capacidad máxima de la habitación (" . $capacidadMax . " personas)."
            );
        }

        return $habitacionRow;
    }

    public function crear(array $datos): array
    {
        $idHabitacion   = (int)$datos['id_habitacion'];
        $fechaEntrada   = trim($datos['fecha_entrada'] ?? '');
        $fechaSalida    = trim($datos['fecha_salida'] ?? '');
        $cantHuespedes  = (int)$datos['cantidad_huespedes'];

        $habitacionRow = $this->validarDatosBasicos($idHabitacion, $fechaEntrada, $fechaSalida, $cantHuespedes);

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idHabitacion]);
            $estadoActual = (int)$lock->fetchColumn();

            if (in_array($estadoActual, self::ESTADOS_HABITACION_NO_RESERVABLES, true)) {
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
            $idTipo = (int)$habitacionRow['id_tipo_habitacion'];
            $precioEfectivo = Habitacion::precioEfectivoNoche($precioBase, $idTipo, $cantHuespedes);
            $total   = $precioEfectivo * $noches;

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

    /**
     * Edita una reserva pendiente o confirmada y recalcula su resumen de pago.
     * Todo ocurre en una sola transacción, con la habitación destino bloqueada (FOR UPDATE)
     * para evitar que dos ediciones simultáneas generen reservas solapadas.
     */
    public function editar(int $idReserva, array $datos): array
    {
        if ($idReserva <= 0) {
            throw new Exception("Ocurrió un error al seleccionar la reserva.");
        }

        $idHabitacion   = (int)$datos['id_habitacion'];
        $fechaEntrada   = trim($datos['fecha_entrada'] ?? '');
        $fechaSalida    = trim($datos['fecha_salida'] ?? '');
        $cantHuespedes  = (int)$datos['cantidad_huespedes'];

        $this->validarDatosBasicos($idHabitacion, $fechaEntrada, $fechaSalida, $cantHuespedes);

        $reservaActual = $this->reservaModel->findById($idReserva);
        if (!$reservaActual) {
            throw new Exception("La reserva no existe.");
        }

        $habitacionActual = $this->habitacionModel->findById((int)$reservaActual['id_habitacion']);
        if ($habitacionActual !== null && (int)$habitacionActual['id_estado_habitacion'] === Habitacion::ESTADO_OCUPADA) {
            throw new Exception("No se puede editar una reserva cuyo check-in ya fue realizado.");
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idHabitacion]);
            $estadoDestino = (int)$lock->fetchColumn();

            $cambiaHabitacion = (int)$reservaActual['id_habitacion'] !== $idHabitacion;
            if ($cambiaHabitacion && in_array($estadoDestino, self::ESTADOS_HABITACION_NO_RESERVABLES, true)) {
                throw new Exception("La habitación seleccionada no está disponible.");
            }

            if ($this->reservaModel->existeSolapamiento($idHabitacion, $fechaEntrada, $fechaSalida, $idReserva)) {
                throw new Exception("La habitación ya tiene una reserva pendiente o confirmada para ese rango de fechas.");
            }

            $reserva = new Reserva();
            $reserva->setId($idReserva);
            $reserva->setIdCliente((int)$datos['id_cliente']);
            $reserva->setIdHabitacion($idHabitacion);
            $reserva->setIdCanalOrigen((int)$datos['id_canal_origen']);
            $reserva->setFechaEntrada($fechaEntrada);
            $reserva->setFechaSalida($fechaSalida);
            $reserva->setCantidadHuespedes($cantHuespedes);
            $reserva->setObservaciones(($datos['observaciones'] ?? '') !== '' ? $datos['observaciones'] : null);

            if (!$this->reservaModel->update($reserva)) {
                throw new Exception("No se pudo actualizar la reserva.");
            }

            $mensaje = "Reserva actualizada exitosamente.";
            $reservaData = $this->reservaModel->findById($idReserva);
            if ($reservaData !== null) {
                (new PagoService())->recalcularResumen($reservaData);
                $mensaje = "Reserva actualizada y resumen de pago recalculado exitosamente.";
            }

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
     * Libera las habitaciones retenidas por No-Show con pago una vez pasada la fecha
     * de salida (o el mismo día después de la hora de checkout).
     * La reserva pasa a NO_SHOW y la habitación a DISPONIBLE.
     * Lo ejecuta cron/no-show-release.php.
     */
    public function liberarNoShowConPago(): array
    {
        $db = $this->reservaModel->getConnection();
        $fechaActual = date('Y-m-d');

        $stmt = $db->prepare("
            SELECT r.id, r.id_habitacion
            FROM Reservas r
            WHERE r.id_estado_reserva = :noshow_pago
              AND (r.fecha_salida < :fecha_hoy
                   OR (r.fecha_salida = :fecha_hoy_2 AND :hora_actual >= :hora_checkout))
        ");
        $stmt->execute([
            ':noshow_pago'   => Reserva::ESTADO_NO_SHOW_CON_PAGO,
            ':fecha_hoy'     => $fechaActual,
            ':fecha_hoy_2'   => $fechaActual,
            ':hora_actual'   => date('H:i:s'),
            ':hora_checkout' => ConfiguracionService::horaCheckoutSql(),
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

        $hoy = date('Y-m-d');
        if ((string)$reservaData['fecha_entrada'] > $hoy) {
            throw new Exception("No se puede realizar el check-in antes de la fecha de entrada.");
        }

        $estadoActual = (int)$reservaData['id_estado_reserva'];
        $vieneDeNoShow = $estadoActual === Reserva::ESTADO_NO_SHOW_CON_PAGO;

        if ($estadoActual !== Reserva::ESTADO_CONFIRMADA && !$vieneDeNoShow) {
            throw new Exception(
                "Solo se puede hacer check-in de reservas confirmadas o en No-Show con pago."
            );
        }

        $resumen = $this->resumenModel->getByReserva($idReserva);
        if ($resumen === null || $resumen->getIdEstadoPago() !== ResumenPago::ESTADO_PAGADO_TOTAL) {
            throw new Exception("No se puede realizar el check-in. La estadía debe estar 100% pagada.");
        }

        $idHabitacion = (int)$reservaData['id_habitacion'];
        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idHabitacion]);
            $estadoHabitacion = (int)$lock->fetchColumn();

            if ($vieneDeNoShow) {
                if ($estadoHabitacion !== Habitacion::ESTADO_OCUPADA) {
                    $db->rollBack();
                    throw new Exception(
                        "La habitación debería estar ocupada (retenida por No-Show)."
                    );
                }
            } else {
                if ($estadoHabitacion === Habitacion::ESTADO_OCUPADA) {
                    $db->rollBack();
                    throw new Exception("La habitación ya se encuentra ocupada.");
                }
                if ($estadoHabitacion !== Habitacion::ESTADO_DISPONIBLE) {
                    $db->rollBack();
                    throw new Exception("La habitación no está disponible para check-in.");
                }

                $roomOk = $this->habitacionModel->cambiarEstado(
                    $idHabitacion,
                    Habitacion::ESTADO_OCUPADA,
                    Habitacion::ESTADO_DISPONIBLE
                );
                if (!$roomOk) {
                    $db->rollBack();
                    throw new Exception("Error al ocupar la habitación.");
                }
            }

            $reservaOk = $this->reservaModel->cambiarEstado(
                $idReserva,
                Reserva::ESTADO_EN_CASA,
                $estadoActual
            );
            if (!$reservaOk) {
                if (!$vieneDeNoShow) {
                    $this->habitacionModel->cambiarEstado($idHabitacion, Habitacion::ESTADO_DISPONIBLE);
                }
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

        $hoy = date('Y-m-d');
        $estadoActual = (int)$reservaData['id_estado_reserva'];
        if ($estadoActual === Reserva::ESTADO_CONFIRMADA && (string)$reservaData['fecha_salida'] > $hoy) {
            throw new Exception("No se puede finalizar la reserva antes de la fecha de salida.");
        }

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

            if ((string)$reservaData['fecha_salida'] > $hoy) {
                $obsActual = (string)($reservaData['observaciones'] ?? '');
                $nuevaObs = $obsActual . ($obsActual !== '' ? "\n" : '') . "- Se retiró de forma temprana el " . $hoy . ".";
                $updObs = $db->prepare("UPDATE Reservas SET observaciones = :obs WHERE id = :id");
                $updObs->execute([':obs' => $nuevaObs, ':id' => $idReserva]);
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

            $hoy = date('Y-m-d');
            $obsActual = (string)($reservaData['observaciones'] ?? '');
            $nuevaObs = $obsActual . ($obsActual !== '' ? "\n" : '') . "- Se retiró de forma temprana el " . $hoy . ".";
            $updObs = $db->prepare("UPDATE Reservas SET observaciones = :obs WHERE id = :id");
            $updObs->execute([':obs' => $nuevaObs, ':id' => $idReserva]);

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
                    $upd = $db->prepare(
                        "UPDATE Resumen_Pago SET id_estado_pago = :estado WHERE id = :id"
                    );
                    $upd->execute([
                        ':estado' => \App\Models\EstadoPago::A_REEMBOLSAB,
                        ':id'     => $resumen->getId()
                    ]);
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

        $hoy = date('Y-m-d');
        if ((string)$reservaData['fecha_entrada'] > $hoy) {
            throw new Exception("No se puede marcar como No-Show antes de la fecha de entrada.");
        }
        if ((string)$reservaData['fecha_entrada'] === $hoy && date('H:i') < ConfiguracionService::horaCheckout()) {
            throw new Exception("El No-Show solo puede marcarse después de las " . ConfiguracionService::horaCheckout() . " del día de entrada.");
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

    public function getOpcionesUpgrade(int $idReserva): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }

        $idTipoActual    = (int)$reservaData['id_tipo_habitacion'];
        $fechaEntrada    = (string)$reservaData['fecha_entrada'];
        $fechaSalida     = (string)$reservaData['fecha_salida'];
        $idHabActual     = (int)$reservaData['id_habitacion'];
        $jerarquia       = Habitacion::PRICE_HIERARCHY_ORDER;
        $posActual       = array_search($idTipoActual, $jerarquia);
        if ($posActual === false) {
            return [];
        }

        $tiposPermitidos = [];
        for ($i = $posActual; $i < count($jerarquia); $i++) {
            $tiposPermitidos[] = $jerarquia[$i];
        }
        $placeholders = implode(',', array_fill(0, count($tiposPermitidos), '?'));

        $db = $this->reservaModel->getConnection();
        $stmt = $db->prepare(
            "SELECT h.id, h.numero, h.piso, h.precio_noche_base,
                    th.descripcion AS tipo, h.id_tipo_habitacion
             FROM Habitaciones h
             JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
             WHERE h.id_tipo_habitacion IN ($placeholders)
               AND h.is_active = 1
               AND h.id_estado_habitacion = ?
               AND h.id != ?
             ORDER BY
               CASE h.id_tipo_habitacion
                 WHEN 3 THEN 4
                 WHEN 2 THEN 3
                 WHEN 4 THEN 2
                 WHEN 1 THEN 1
               END DESC,
               h.precio_noche_base ASC"
        );
        $params = $tiposPermitidos;
        $params[] = Habitacion::ESTADO_DISPONIBLE;
        $params[] = $idHabActual;
        $stmt->execute($params);
        $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $opciones = [];
        foreach ($candidatos as $c) {
            if (!$this->reservaModel->existeSolapamiento(
                (int)$c['id'], $fechaEntrada, $fechaSalida
            )) {
                $c['capacidad'] = Habitacion::capacidadParaTipo((int)$c['id_tipo_habitacion']);
                $opciones[] = $c;
            }
        }

        return $opciones;
    }

    public function upgradeHabitacion(int $idReserva, int $idNuevaHabitacion): array
    {
        $reservaData = $this->reservaModel->findById($idReserva);
        if (!$reservaData) {
            throw new Exception("La reserva no existe.");
        }

        $estadoReserva = (int)$reservaData['id_estado_reserva'];
        if ($estadoReserva !== Reserva::ESTADO_CONFIRMADA && $estadoReserva !== Reserva::ESTADO_EN_CASA) {
            throw new Exception("Solo se puede upgradear una reserva confirmada o en estadía.");
        }

        $nuevaHab = $this->habitacionModel->findById($idNuevaHabitacion);
        if (!$nuevaHab) {
            throw new Exception("La habitación de destino no existe.");
        }
        if ((int)$nuevaHab['id_estado_habitacion'] !== Habitacion::ESTADO_DISPONIBLE) {
            throw new Exception("La habitación de destino no está disponible.");
        }

        $idTipoActual = (int)$reservaData['id_tipo_habitacion'];
        $idTipoNuevo  = (int)$nuevaHab['id_tipo_habitacion'];
        $jerarquia    = Habitacion::PRICE_HIERARCHY_ORDER;
        $posActual    = array_search($idTipoActual, $jerarquia);
        $posNuevo     = array_search($idTipoNuevo, $jerarquia);
        if ($posActual === false || $posNuevo === false || $posNuevo < $posActual) {
            throw new Exception("La habitación seleccionada no es igual o superior en jerarquía.");
        }

        $fechaEntrada = (string)$reservaData['fecha_entrada'];
        $fechaSalida  = (string)$reservaData['fecha_salida'];
        if ($this->reservaModel->existeSolapamiento($idNuevaHabitacion, $fechaEntrada, $fechaSalida, $idReserva)) {
            throw new Exception("La habitación de destino no está disponible en esas fechas.");
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $idHabOriginal = (int)$reservaData['id_habitacion'];

            $updateReserva = $db->prepare(
                "UPDATE Reservas SET id_habitacion = :nueva WHERE id = :id"
            );
            $updateReserva->execute([
                ':nueva' => $idNuevaHabitacion,
                ':id'    => $idReserva
            ]);

            if ($estadoReserva === Reserva::ESTADO_EN_CASA) {
                $this->habitacionModel->cambiarEstado(
                    $idHabOriginal,
                    Habitacion::ESTADO_MANTENIMIENTO,
                    Habitacion::ESTADO_OCUPADA
                );
                $this->habitacionModel->cambiarEstado(
                    $idNuevaHabitacion,
                    Habitacion::ESTADO_OCUPADA,
                    Habitacion::ESTADO_DISPONIBLE
                );
            }

            $habOriginalNum = (int)$reservaData['habitacion_numero'];
            $habOriginalTipo = (string)($reservaData['tipo_habitacion_descripcion'] ?? '');
            $precioOriginal  = (float)$reservaData['precio_noche_base'];
            $nuevoNum = (int)$nuevaHab['numero'];
            $nuevoTipo = (string)($nuevaHab['tipo'] ?? '');
            $hoy = date('Y-m-d');
            $obsActual = (string)($reservaData['observaciones'] ?? '');
            $nuevaObs = $obsActual
                . ($obsActual !== '' ? "\n" : '')
                . "- Upgrade: de #" . $habOriginalNum . " (" . $habOriginalTipo . " $" . number_format($precioOriginal, 2, ',', '.') . ") → #" . $nuevoNum . " (" . $nuevoTipo . ") sin costo adicional el " . $hoy . ".";

            $updObs = $db->prepare("UPDATE Reservas SET observaciones = :obs WHERE id = :id");
            $updObs->execute([':obs' => $nuevaObs, ':id' => $idReserva]);

            $db->commit();
            return [
                'success' => true,
                'message' => 'Upgrade realizado. Huésped movido a #' . $nuevoNum . ' (' . $nuevoTipo . ') sin costo adicional.'
            ];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
