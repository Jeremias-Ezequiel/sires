<?php

declare(strict_types=1);

namespace App\Services;

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

class ReservaService
{
    private Reserva $reservaModel;
    private Habitacion $habitacionModel;
    private ResumenPago $resumenModel;

    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->auditoria = new AuditoriaService();
        $this->reservaModel   = new Reserva();
        $this->habitacionModel = new Habitacion();
        $this->resumenModel   = new ResumenPago();
    }

    /**
     * Estados de habitación en los que no se puede asignar una reserva.
     * Ocupada, Sucia y Limpiando son estados del día: no impiden reservar a futuro
     * (el check-in sigue exigiendo que la habitación esté Disponible).
     */
    private const ESTADOS_HABITACION_NO_RESERVABLES = [
        Habitacion::ESTADO_MANTENIMIENTO,
        Habitacion::ESTADO_BLOQUEADA,
    ];

    public static function habitacionReservable(int $idEstadoHabitacion): bool
    {
        return !in_array($idEstadoHabitacion, self::ESTADOS_HABITACION_NO_RESERVABLES, true);
    }

    /**
     * Validaciones comunes a crear y editar: cliente, fechas, existencia de la habitación y capacidad.
     * Devuelve la fila de la habitación.
     */
    private function validarDatosBasicos(int $idCliente, int $idHabitacion, string $fechaEntrada, string $fechaSalida, int $cantHuespedes): array
    {
        if ($idCliente <= 0 || (new Clientes())->getById($idCliente) === null) {
            throw new Exception("El cliente seleccionado no existe.");
        }

        if ($fechaEntrada === '' || $fechaSalida === '') {
            throw new Exception("Las fechas de entrada y salida son obligatorias.");
        }
        if (!self::esFechaValida($fechaEntrada)) {
            throw new Exception("La fecha de entrada no es válida.");
        }
        if (!self::esFechaValida($fechaSalida)) {
            throw new Exception("La fecha de salida no es válida.");
        }
        if ($fechaSalida <= $fechaEntrada) {
            throw new Exception("La fecha de salida debe ser posterior a la fecha de entrada.");
        }
        $maxNoches = ConfiguracionService::maxNochesReserva();
        $noches = Tarifa::noches($fechaEntrada, $fechaSalida);
        if ($noches > $maxNoches) {
            throw new Exception("La reserva no puede superar las " . $maxNoches . " noches (se pidieron " . $noches . ").");
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

    /**
     * Guarda el precio pactado por noche y crea el Resumen_Pago inicial (sin cobros)
     * de una reserva recién insertada. Debe llamarse dentro de la transacción del alta.
     */
    private function crearResumenInicial(
        int $idReserva,
        array $habitacionRow,
        string $fechaEntrada,
        string $fechaSalida,
        int $cantHuespedes
    ): void {
        $noches = Tarifa::noches($fechaEntrada, $fechaSalida);
        $precioEfectivo = Habitacion::precioEfectivoNoche(
            (float)$habitacionRow['precio_noche_base'],
            (int)$habitacionRow['id_tipo_habitacion'],
            $cantHuespedes
        );
        $total = Tarifa::total($precioEfectivo, $noches);

        $this->reservaModel->actualizarPrecioPactado($idReserva, $precioEfectivo);

        $insertResumen = $this->reservaModel->getConnection()->prepare(
            "INSERT INTO Resumen_Pago (id_reserva, id_estado_pago, monto_total, monto_cobrado, saldo_pendiente)
             VALUES (:id_reserva, :estado, :total, 0, :saldo)"
        );
        $insertResumen->execute([
            ':id_reserva' => $idReserva,
            ':estado'     => ResumenPago::ESTADO_PENDIENTE,
            ':total'      => $total,
            ':saldo'      => $total
        ]);
    }

    private static function validarEntradaNoPasada(string $fechaEntrada): void
    {
        if ($fechaEntrada < date('Y-m-d')) {
            throw new Exception("La fecha de entrada no puede ser anterior a hoy.");
        }
    }

    /** Precio por noche acordado de una reserva (fila de findById), con fallback al precio actual. */
    private static function precioNocheReserva(array $reserva): float
    {
        if (isset($reserva['precio_noche_pactado'])) {
            return (float)$reserva['precio_noche_pactado'];
        }
        return Habitacion::precioEfectivoNoche(
            (float)$reserva['precio_noche_base'],
            (int)$reserva['id_tipo_habitacion'],
            (int)$reserva['cantidad_huespedes']
        );
    }

    /** Verifica que la fecha tenga formato Y-m-d y exista en el calendario (ej. rechaza 2026-02-30). */
    private static function esFechaValida(string $fecha): bool
    {
        $dt = \DateTime::createFromFormat('!Y-m-d', $fecha);
        return $dt !== false && $dt->format('Y-m-d') === $fecha;
    }

    public function crear(array $datos): array
    {
        $idHabitacion   = (int)$datos['id_habitacion'];
        $fechaEntrada   = trim($datos['fecha_entrada'] ?? '');
        $fechaSalida    = trim($datos['fecha_salida'] ?? '');
        $cantHuespedes  = (int)$datos['cantidad_huespedes'];

        $habitacionRow = $this->validarDatosBasicos((int)($datos['id_cliente'] ?? 0), $idHabitacion, $fechaEntrada, $fechaSalida, $cantHuespedes);
        self::validarEntradaNoPasada($fechaEntrada);

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

            $nuevoIdReserva = (int)$db->lastInsertId();
            $this->crearResumenInicial($nuevoIdReserva, $habitacionRow, $fechaEntrada, $fechaSalida, $cantHuespedes);

            $this->auditoria->registrar('reserva.crear', 'reserva', $nuevoIdReserva, 'Alta de reserva');
            (new MailService())->encolarConfirmacion($nuevoIdReserva);
            $db->commit();
            return ['success' => true, 'message' => 'Reserva registrada exitosamente.'];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
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

        $this->validarDatosBasicos((int)($datos['id_cliente'] ?? 0), $idHabitacion, $fechaEntrada, $fechaSalida, $cantHuespedes);

        $reservaActual = $this->reservaModel->findById($idReserva);
        if (!$reservaActual) {
            throw new Exception("La reserva no existe.");
        }

        $estadoReserva = (int)$reservaActual['id_estado_reserva'];
        if ($estadoReserva === Reserva::ESTADO_EN_CASA) {
            throw new Exception("No se puede editar una reserva cuyo check-in ya fue realizado.");
        }
        if ($estadoReserva !== Reserva::ESTADO_PENDIENTE && $estadoReserva !== Reserva::ESTADO_CONFIRMADA) {
            throw new Exception("Solo se pueden editar reservas pendientes o confirmadas.");
        }

        // Una confirmada cuyo día de entrada ya llegó se resuelve con check-in o No-Show, no corriendo la fecha.
        $entradaActual = (string)$reservaActual['fecha_entrada'];
        if ($fechaEntrada !== $entradaActual) {
            self::validarEntradaNoPasada($fechaEntrada);
        }
        if ($estadoReserva === Reserva::ESTADO_CONFIRMADA
            && $entradaActual <= date('Y-m-d')
            && $fechaEntrada !== $entradaActual) {
            throw new Exception(
                "No se puede cambiar la fecha de entrada de una reserva confirmada cuyo día de entrada ya llegó. " .
                "Realice el check-in o márquela como No-Show."
            );
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();
        $antes = $this->auditoria->foto('reserva', $idReserva);

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

            // Se respeta el precio pactado si no cambian la habitación ni la ocupación
            // (ej. solo se corren las fechas). Si cambian, se reprecia con el precio actual.
            $precioPactado = $reservaActual['precio_noche_pactado'] ?? null;
            $mantienePrecio = !$cambiaHabitacion
                && $cantHuespedes === (int)$reservaActual['cantidad_huespedes']
                && $precioPactado !== null;

            $mensaje = "Reserva actualizada exitosamente.";
            $reservaData = $this->reservaModel->findById($idReserva);
            if ($reservaData !== null) {
                (new PagoService())->recalcularResumen(
                    $reservaData,
                    $mantienePrecio ? (float)$precioPactado : null
                );
                $mensaje = $mantienePrecio
                    ? "Reserva actualizada. Se mantuvo el precio pactado por noche."
                    : "Reserva actualizada y resumen de pago recalculado con el precio actual.";

                $resumenNuevo = $this->resumenModel->getByReserva($idReserva);
                if ($resumenNuevo !== null && $resumenNuevo->getSaldoPendiente() > 0 && $resumenNuevo->getMontoPagado() > 0) {
                    $mensaje .= " Saldo pendiente: $" . number_format($resumenNuevo->getSaldoPendiente(), 2, ',', '.') . ".";
                }
            }

            $this->auditoria->registrar('reserva.editar', 'reserva', $idReserva, 'Edición de reserva', $antes);
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
        $senaPct = ConfiguracionService::senaMinimaPct($reservaData['fecha_alta']);
        $senaMinima = ReglasPago::senaMinima($resumen->getTotal(), $senaPct);
        if (!ReglasPago::alcanzaSena($resumen->getMontoPagado(), $resumen->getTotal(), $senaPct)) {
            throw new Exception(
                "Para confirmar se necesita una seña mínima del " . rtrim(rtrim(number_format($senaPct, 2, ',', ''), '0'), ',') .
                " % ($" . number_format($senaMinima, 2, ',', '.') . "). Cobrado: $" .
                number_format($resumen->getMontoPagado(), 2, ',', '.') . "."
            );
        }

        $antes = $this->auditoria->foto('reserva', $idReserva);
        $ok = $this->reservaModel->cambiarEstado($idReserva, Reserva::ESTADO_CONFIRMADA, Reserva::ESTADO_PENDIENTE);
        if (!$ok) {
            throw new Exception("No se pudo confirmar la reserva.");
        }
        $this->auditoria->registrar('reserva.confirmar', 'reserva', $idReserva, 'Confirmación', $antes);

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
        if ((string)$reservaData['fecha_salida'] <= $hoy) {
            throw new Exception(
                "No se puede realizar el check-in: la estadía ya venció (fecha de salida " .
                date('d/m/Y', strtotime((string)$reservaData['fecha_salida'])) . ")."
            );
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
        $antes = $this->auditoria->foto('reserva', $idReserva);

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idHabitacion]);
            $estadoHabitacion = (int)$lock->fetchColumn();

            if ($vieneDeNoShow) {
                // La habitación quedó Retenida al marcar el No-Show (o Disponible si no se pudo retener).
                $estadoOrigen = $estadoHabitacion;
                if ($estadoOrigen !== Habitacion::ESTADO_RETENIDA && $estadoOrigen !== Habitacion::ESTADO_DISPONIBLE) {
                    throw new Exception("La habitación no está retenida ni disponible para el check-in.");
                }
                if (!$this->habitacionModel->cambiarEstado($idHabitacion, Habitacion::ESTADO_OCUPADA, $estadoOrigen)) {
                    throw new Exception("Error al ocupar la habitación.");
                }
            } else {
                if ($estadoHabitacion === Habitacion::ESTADO_OCUPADA) {
                    throw new Exception("La habitación ya se encuentra ocupada.");
                }
                if ($estadoHabitacion !== Habitacion::ESTADO_DISPONIBLE) {
                    throw new Exception("La habitación no está disponible para check-in.");
                }

                $roomOk = $this->habitacionModel->cambiarEstado(
                    $idHabitacion,
                    Habitacion::ESTADO_OCUPADA,
                    Habitacion::ESTADO_DISPONIBLE
                );
                if (!$roomOk) {
                    throw new Exception("Error al ocupar la habitación.");
                }
            }

            $reservaOk = $this->reservaModel->cambiarEstado(
                $idReserva,
                Reserva::ESTADO_EN_CASA,
                $estadoActual
            );
            if (!$reservaOk) {
                throw new Exception("Error al actualizar el estado de la reserva.");
            }

            $this->auditoria->registrar('reserva.checkin', 'reserva', $idReserva, 'Check-in', $antes);
            $db->commit();
            return ['success' => true, 'message' => 'Check-in realizado exitosamente.'];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
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
        $antes = $this->auditoria->foto('reserva', $idReserva);

        try {
            $reservaOk = $this->reservaModel->cambiarEstado($idReserva, Reserva::ESTADO_FINALIZADA, $estadoActual);
            if (!$reservaOk) {
                throw new Exception("No se pudo finalizar la reserva.");
            }

            if ((string)$reservaData['fecha_salida'] > $hoy) {
                $obsActual = (string)($reservaData['observaciones'] ?? '');
                $nuevaObs = ReglasReserva::agregarObservacion(
                    ReglasReserva::observacionesLegibles($obsActual),
                    "Se retiró de forma temprana el " . $hoy . "."
                );
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

            $this->auditoria->registrar('reserva.checkout', 'reserva', $idReserva, 'Check-out', $antes);
            $db->commit();
            return ['success' => true, 'message' => 'Checkout realizado. La habitación pasa a limpieza.'];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
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
        $antes = $this->auditoria->foto('reserva', $idReserva);

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
            $nuevaObs = ReglasReserva::agregarObservacion(
                    ReglasReserva::observacionesLegibles($obsActual),
                    "Se retiró de forma temprana el " . $hoy . "."
                );
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

            $this->auditoria->registrar('reserva.checkout_anticipado', 'reserva', $idReserva, 'Check-out anticipado', $antes);
            $db->commit();
            return ['success' => true, 'message' => 'Checkout anticipado realizado. Sin reembolso por días no usados.'];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
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
        $antes = $this->auditoria->foto('reserva', $idReserva);

        try {
            $lockDestino = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lockDestino->execute([':id' => $idNuevaHabitacion]);
            if ((int)$lockDestino->fetchColumn() !== Habitacion::ESTADO_DISPONIBLE) {
                throw new Exception("La habitación destino no está disponible.");
            }

            $capacidadDestino = Habitacion::capacidadParaTipo((int)$nuevaHabitacion['id_tipo_habitacion']);
            if ((int)$reservaData['cantidad_huespedes'] > $capacidadDestino) {
                throw new Exception("La habitación destino no tiene capacidad para " . (int)$reservaData['cantidad_huespedes'] . " huéspedes.");
            }

            if ($this->reservaModel->existeSolapamiento(
                $idNuevaHabitacion,
                date('Y-m-d'),
                (string)$reservaData['fecha_salida'],
                $idReserva
            )) {
                throw new Exception("La habitación destino tiene reservas durante lo que resta de la estadía.");
            }

            $idHabOriginal = (int)$reservaData['id_habitacion'];

            $roomNuevaOk = $this->habitacionModel->cambiarEstado(
                $idNuevaHabitacion,
                Habitacion::ESTADO_OCUPADA,
                Habitacion::ESTADO_DISPONIBLE
            );
            if (!$roomNuevaOk) {
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

            $this->auditoria->registrar('reserva.mover', 'reserva', $idReserva, 'Traslado de habitación', $antes);
            $db->commit();
            return [
                'success' => true,
                'message' => 'Huésped trasladado a habitación #' . $nuevaHabitacion['numero'] . '. La habitación original pasa a mantenimiento.'
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
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
            $disponible = $this->buscarHabitacionParaExtension(
                $reservaData,
                $fechaSalidaActual,
                $nuevaFechaSalida
            );

            if ($disponible === null) {
                throw new Exception(
                    "La habitación actual está reservada para esas fechas y no hay otra habitación disponible para la extensión."
                );
            }

            return $this->crearSplitReservation($idReserva, (int)$disponible['id'], $fechaSalidaActual, $nuevaFechaSalida);
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();
        $antes = $this->auditoria->foto('reserva', $idReserva);

        try {
            $update = $db->prepare(
                "UPDATE Reservas SET fecha_salida = :nueva WHERE id = :id"
            );
            $update->execute([':nueva' => $nuevaFechaSalida, ':id' => $idReserva]);

            $reservaActualizada = $this->reservaModel->findById($idReserva);
            if ($reservaActualizada !== null) {
                $this->resumenModel->recalcular($reservaActualizada);
            }

            $this->auditoria->registrar('reserva.extender', 'reserva', $idReserva, 'Extensión de estadía', $antes);
            $db->commit();
            return ['success' => true, 'message' => 'Estadía extendida exitosamente.'];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Busca una habitación libre para las noches extra de una estadía.
     * Prioriza el mismo tipo de habitación; si no hay, cualquier otra con capacidad suficiente.
     * No mira el estado de hoy (salvo Mantenimiento/Bloqueada): el traslado es al final de la estadía.
     */
    private function buscarHabitacionParaExtension(array $reservaData, string $desde, string $hasta): ?array
    {
        $idHabitacionActual = (int)$reservaData['id_habitacion'];
        $huespedes = (int)$reservaData['cantidad_huespedes'];
        $habitacionActual = $this->habitacionModel->findById($idHabitacionActual);
        $tipoActual = $habitacionActual !== null ? (int)$habitacionActual['id_tipo_habitacion'] : 0;

        $mismoTipo = null;
        $otroTipo  = null;
        foreach ($this->habitacionModel->getAllWithFilters(null, null, null, null) as $hab) {
            $idHab = (int)$hab['id'];
            $idTipo = (int)$hab['id_tipo_habitacion'];
            if ($idHab === $idHabitacionActual
                || !self::habitacionReservable((int)$hab['id_estado_habitacion'])
                || Habitacion::capacidadParaTipo($idTipo) < $huespedes
                || $this->reservaModel->existeSolapamiento($idHab, $desde, $hasta)) {
                continue;
            }
            if ($idTipo === $tipoActual) {
                $mismoTipo = $hab;
                break;
            }
            $otroTipo ??= $hab;
        }

        return $mismoTipo ?? $otroTipo;
    }

    /**
     * Crea una reserva nueva (pendiente, con su precio pactado y su Resumen_Pago) para las
     * noches extra en otra habitación. La habitación nueva no se ocupa ahora: el huésped
     * se traslada al final de la estadía actual, con el check-in de la reserva nueva.
     */
    private function crearSplitReservation(
        int $idReservaOriginal,
        int $idNuevaHabitacion,
        string $fechaInicioSplit,
        string $nuevaFechaSalida
    ): array {
        $reservaOriginal = $this->reservaModel->findById($idReservaOriginal);
        if (!$reservaOriginal) {
            throw new Exception("La reserva no existe.");
        }

        $db = $this->reservaModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idNuevaHabitacion]);
            if (!self::habitacionReservable((int)$lock->fetchColumn())) {
                throw new Exception("La habitación elegida para la extensión no está disponible.");
            }
            if ($this->reservaModel->existeSolapamiento($idNuevaHabitacion, $fechaInicioSplit, $nuevaFechaSalida)) {
                throw new Exception("La habitación elegida para la extensión ya tiene reservas en esas fechas.");
            }

            $habitacionRow = $this->habitacionModel->findById($idNuevaHabitacion);
            $cantHuespedes = (int)$reservaOriginal['cantidad_huespedes'];

            $nuevaReserva = new Reserva();
            $nuevaReserva->setIdCliente((int)$reservaOriginal['id_cliente']);
            $nuevaReserva->setIdHabitacion($idNuevaHabitacion);
            $nuevaReserva->setIdCanalOrigen((int)$reservaOriginal['id_canal_origen']);
            $nuevaReserva->setFechaEntrada($fechaInicioSplit);
            $nuevaReserva->setFechaSalida($nuevaFechaSalida);
            $nuevaReserva->setCantidadHuespedes($cantHuespedes);
            $nuevaReserva->setObservaciones(
                "Extensión de reserva #" . $idReservaOriginal . ". Huésped se muda a esta habitación."
            );
            $nuevaReserva->setCreadoPor((int)($_SESSION['user_id'] ?? $reservaOriginal['creado_por']));
            if (!$this->reservaModel->save($nuevaReserva)) {
                throw new Exception("No se pudo registrar la reserva de extensión.");
            }

            $nuevoIdReserva = (int)$db->lastInsertId();
            $this->crearResumenInicial($nuevoIdReserva, $habitacionRow, $fechaInicioSplit, $nuevaFechaSalida, $cantHuespedes);

            $this->auditoria->registrar('reserva.crear', 'reserva', $nuevoIdReserva, 'Alta por extensión de la reserva #' . $idReservaOriginal);
            $db->commit();
            return [
                'success' => true,
                'message' => 'Estadía dividida. Se creó la reserva #' . $nuevoIdReserva .
                    ' en la habitación #' . $habitacionRow['numero'] . ' a partir del ' .
                    date('d/m/Y', strtotime($fechaInicioSplit)) .
                    '. Registre el pago y haga el check-in de esa reserva al momento del traslado.'
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
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
        $antes = $this->auditoria->foto('reserva', $idReserva);

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
            $nuevaObs = ReglasReserva::agregarObservacion(
                ReglasReserva::observacionesLegibles($obsActual),
                "Upgrade: de #" . $habOriginalNum . " (" . $habOriginalTipo . " $" . number_format($precioOriginal, 2, ',', '.') . ") → #" . $nuevoNum . " (" . $nuevoTipo . ") sin costo adicional el " . $hoy . "."
            );

            $updObs = $db->prepare("UPDATE Reservas SET observaciones = :obs WHERE id = :id");
            $updObs->execute([':obs' => $nuevaObs, ':id' => $idReserva]);

            $this->auditoria->registrar('reserva.upgrade', 'reserva', $idReserva, 'Upgrade de habitación', $antes);
            $db->commit();
            return [
                'success' => true,
                'message' => 'Upgrade realizado. Huésped movido a #' . $nuevoNum . ' (' . $nuevoTipo . ') sin costo adicional.'
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
