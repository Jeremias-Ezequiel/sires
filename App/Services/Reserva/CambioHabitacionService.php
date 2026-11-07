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
 * Traslado a otra habitación y upgrade de categoría.
 */
final class CambioHabitacionService extends ServicioReservaBase
{
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
