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
 * Estadía del huésped: check-in, check-out (normal y anticipado) y extensión, con split de
 * reserva cuando la habitación no está libre para las noches extra.
 */
final class EstadiaService extends ServicioReservaBase
{
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
        $tipo = ReglasReserva::tipoCheckout($estadoActual, (string)$reservaData['fecha_salida'], $hoy);
        if ($tipo === null) {
            throw new Exception("Solo se puede hacer el check-out de una reserva en estadía.");
        }
        if ($tipo === ReglasReserva::CHECKOUT_ANTICIPADO) {
            throw new Exception("La salida pactada es el " . date('d/m/Y', (int)strtotime((string)$reservaData['fecha_salida'])) . ". Para que el huésped se retire antes, usá Checkout anticipado.");
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
            $this->reservaModel->registrarCheckout($idReserva, $hoy);

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
        $tipo = ReglasReserva::tipoCheckout((int)$reservaData['id_estado_reserva'], (string)$reservaData['fecha_salida'], date('Y-m-d'));
        if ($tipo === null) {
            throw new Exception("Solo se puede hacer checkout anticipado de reservas en estadía.");
        }
        if ($tipo === ReglasReserva::CHECKOUT) {
            throw new Exception("Hoy es el día de salida pactado: usá Check-out.");
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
            // Se retiró hoy: el Planning corta acá la barra (la salida pactada no cambia)
            $this->reservaModel->registrarCheckout($idReserva, $hoy);
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
                || !ReglasReserva::habitacionReservable((int)$hab['id_estado_habitacion'])
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
            if (!ReglasReserva::habitacionReservable((int)$lock->fetchColumn())) {
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
}
