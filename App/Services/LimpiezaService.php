<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use App\Models\Habitacion;
use App\Models\Reserva;
use PDO;
use PDOException;

class LimpiezaService
{
    /** Prioridades de la lista de housekeeping (menor = antes). */
    public const PRIORIDAD_URGENTE     = 1;
    public const PRIORIDAD_LLEGA_HOY   = 2;
    public const PRIORIDAD_LLEGA_MANANA = 3;
    public const PRIORIDAD_SIN_LLEGADA = 4;

    private Habitacion $habitacionModel;
    private Reserva $reservaModel;

    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->auditoria = new AuditoriaService();
        $this->habitacionModel = new Habitacion();
        $this->reservaModel    = new Reserva();
    }

    /**
     * Lista de housekeeping: habitaciones en limpieza (en curso) y sucias (pendientes),
     * cada una con su prioridad y el motivo, ordenadas por:
     *   1. urgente (marcada por recepción), 2. llega un huésped hoy, 3. llega mañana,
     *   4. sin llegada próxima; a igual prioridad, la que lleva más tiempo sucia y luego por piso.
     * Es una guía: la urgencia real la sigue decidiendo recepción.
     */
    public function obtenerTablero(): array
    {
        $hoy    = date('Y-m-d');
        $manana = date('Y-m-d', strtotime('+1 day'));

        $llegaHoy    = $this->llegadasPorHabitacion($hoy);
        $llegaManana = $this->llegadasPorHabitacion($manana);

        $enCurso = [];
        $pendientes = [];
        foreach ($this->habitacionModel->getParaLimpieza() as $h) {
            $id = (int)$h['id'];

            if ((int)$h['limpieza_urgente'] === 1) {
                [$prioridad, $motivo] = [self::PRIORIDAD_URGENTE, 'Urgente (marcada por recepción)'];
            } elseif (isset($llegaHoy[$id])) {
                [$prioridad, $motivo] = [self::PRIORIDAD_LLEGA_HOY, 'Llega un huésped hoy'];
            } elseif (isset($llegaManana[$id])) {
                [$prioridad, $motivo] = [self::PRIORIDAD_LLEGA_MANANA, 'Llega un huésped mañana'];
            } else {
                [$prioridad, $motivo] = [self::PRIORIDAD_SIN_LLEGADA, 'Sin llegada próxima'];
            }

            $fila = $h + [
                'prioridad'       => $prioridad,
                'motivo'          => $motivo,
                'minutos_sucia'   => $h['sucia_desde'] !== null
                    ? max(0, intdiv(time() - strtotime((string)$h['sucia_desde']), 60))
                    : null,
            ];

            if ((int)$h['id_estado_habitacion'] === Habitacion::ESTADO_LIMPIANDO) {
                $enCurso[] = $fila;
            } else {
                $pendientes[] = $fila;
            }
        }

        usort($enCurso, [$this, 'compararPrioridad']);
        usort($pendientes, [$this, 'compararPrioridad']);

        return ['enCurso' => $enCurso, 'pendientes' => $pendientes];
    }

    /**
     * Marca o desmarca una habitación como limpieza urgente. Solo sucias o en limpieza;
     * el trigger de la migración 011 la desmarca sola cuando la habitación sale de esos estados.
     */
    public function marcarUrgente(int $idHabitacion, bool $urgente): array
    {
        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();
        $antes = $this->auditoria->foto('habitacion', $idHabitacion);

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion, numero FROM Habitaciones
                 WHERE id = :id AND is_active = :activa FOR UPDATE"
            );
            $lock->execute([':id' => $idHabitacion, ':activa' => Habitacion::ACTIVE]);
            $hab = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$hab) {
                throw new Exception("La habitación no existe.");
            }
            $estado = (int)$hab['id_estado_habitacion'];
            if ($estado !== Habitacion::ESTADO_SUCIA && $estado !== Habitacion::ESTADO_LIMPIANDO) {
                throw new Exception("Solo se puede marcar como urgente una habitación sucia o en limpieza.");
            }

            $stmt = $db->prepare("UPDATE Habitaciones SET limpieza_urgente = :urgente WHERE id = :id");
            $stmt->execute([':urgente' => $urgente ? 1 : 0, ':id' => $idHabitacion]);

            $this->auditoria->registrar('habitacion.urgente', 'habitacion', $idHabitacion, $urgente ? 'Marcada como limpieza urgente' : 'Se quita la limpieza urgente', $antes);
            $db->commit();
            $numero = (string)$hab['numero'];
            return [
                'success' => true,
                'message' => $urgente
                    ? "Habitación #{$numero} marcada como limpieza urgente."
                    : "Habitación #{$numero} ya no es urgente.",
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<int, true> id_habitacion => true, con llegada pendiente de check-in esa fecha */
    private function llegadasPorHabitacion(string $fecha): array
    {
        $ids = [];
        foreach ($this->reservaModel->getLlegadasDelDia($fecha) as $l) {
            if ((int)$l['id_estado_reserva'] !== Reserva::ESTADO_EN_CASA) {
                $ids[(int)$l['id_habitacion']] = true;
            }
        }
        return $ids;
    }

    private function compararPrioridad(array $a, array $b): int
    {
        return [$a['prioridad'], $a['sucia_desde'] === null, $a['sucia_desde'], (int)$a['piso'], (string)$a['numero']]
           <=> [$b['prioridad'], $b['sucia_desde'] === null, $b['sucia_desde'], (int)$b['piso'], (string)$b['numero']];
    }

    public function iniciarLimpieza(int $idHabitacion): array
    {
        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();
        $antes = $this->auditoria->foto('habitacion', $idHabitacion);

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idHabitacion]);
            $estado = (int)$lock->fetchColumn();

            if ($estado !== Habitacion::ESTADO_SUCIA) {
                $db->rollBack();
                throw new Exception($estado === Habitacion::ESTADO_LIMPIANDO
                    ? "Esta habitación ya se está limpiando."
                    : "La habitación no está marcada como sucia.");
            }

            $stmt = $db->prepare(
                "UPDATE Habitaciones
                 SET id_estado_habitacion = :nuevo
                 WHERE id = :id AND id_estado_habitacion = :actual"
            );
            $stmt->execute([
                ':nuevo'  => Habitacion::ESTADO_LIMPIANDO,
                ':id'     => $idHabitacion,
                ':actual' => Habitacion::ESTADO_SUCIA
            ]);

            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                throw new Exception("No se pudo iniciar la limpieza. La habitación ya no está sucia.");
            }

            $this->auditoria->registrar('habitacion.limpieza_inicio', 'habitacion', $idHabitacion, 'Inicio de limpieza', $antes);
            $db->commit();
            return ['success' => true, 'message' => 'Limpieza iniciada para la habitación.'];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function completarLimpieza(int $idHabitacion): array
    {
        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();
        $antes = $this->auditoria->foto('habitacion', $idHabitacion);

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idHabitacion]);
            $estado = (int)$lock->fetchColumn();

            if ($estado !== Habitacion::ESTADO_LIMPIANDO) {
                $db->rollBack();
                throw new Exception($estado === Habitacion::ESTADO_DISPONIBLE
                    ? "Esta habitación ya figura como limpia."
                    : "La habitación no está en limpieza.");
            }

            $stmt = $db->prepare(
                "UPDATE Habitaciones
                 SET id_estado_habitacion = :nuevo
                 WHERE id = :id AND id_estado_habitacion = :actual"
            );
            $stmt->execute([
                ':nuevo'  => Habitacion::ESTADO_DISPONIBLE,
                ':id'     => $idHabitacion,
                ':actual' => Habitacion::ESTADO_LIMPIANDO
            ]);

            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                throw new Exception("No se pudo completar la limpieza.");
            }

            $this->auditoria->registrar('habitacion.limpieza_fin', 'habitacion', $idHabitacion, 'Limpieza terminada', $antes);
            $db->commit();
            return ['success' => true, 'message' => 'Limpieza completada. Habitación disponible para venta.'];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function obtenerEstadoLimpieza(int $idHabitacion): array
    {
        $habitacion = $this->habitacionModel->findById($idHabitacion);
        if (!$habitacion) {
            throw new Exception("La habitación no existe.");
        }

        return [
            'id_estado' => (int)$habitacion['id_estado_habitacion'],
            'estado'    => $habitacion['estado']
        ];
    }

    public function listarEnLimpieza(): array
    {
        $todas = $this->habitacionModel->getAllWithFilters(null, null, null, null);
        $result = [];
        foreach ($todas as $h) {
            $estado = (int)$h['id_estado_habitacion'];
            if ($estado === Habitacion::ESTADO_SUCIA || $estado === Habitacion::ESTADO_LIMPIANDO) {
                $result[] = $h;
            }
        }
        return $result;
    }
}