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
 * Alta, edición y confirmación de reservas (validación de datos, precio pactado y resumen de pago inicial).
 */
final class AltaReservaService extends ServicioReservaBase
{
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
        if (!ReglasReserva::esFechaValida($fechaEntrada)) {
            throw new Exception("La fecha de entrada no es válida.");
        }
        if (!ReglasReserva::esFechaValida($fechaSalida)) {
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

    private static function validarEntradaNoPasada(string $fechaEntrada): void
    {
        if ($fechaEntrada < date('Y-m-d')) {
            throw new Exception("La fecha de entrada no puede ser anterior a hoy.");
        }
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

            if (in_array($estadoActual, ReglasReserva::ESTADOS_HABITACION_NO_RESERVABLES, true)) {
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
            if ($cambiaHabitacion && in_array($estadoDestino, ReglasReserva::ESTADOS_HABITACION_NO_RESERVABLES, true)) {
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
}
