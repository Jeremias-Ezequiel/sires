<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use App\Models\Habitacion;
use PDO;
use PDOException;

class LimpiezaService
{
    private Habitacion $habitacionModel;

    public function __construct()
    {
        $this->habitacionModel = new Habitacion();
    }

    public function iniciarLimpieza(int $idHabitacion): array
    {
        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idHabitacion]);
            $estado = (int)$lock->fetchColumn();

            if ($estado !== Habitacion::ESTADO_SUCIA) {
                $db->rollBack();
                throw new Exception("La habitación no está marcada como sucia. Estado actual: " . $estado);
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

            $db->commit();
            return ['success' => true, 'message' => 'Limpieza iniciada para la habitación.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function completarLimpieza(int $idHabitacion): array
    {
        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $idHabitacion]);
            $estado = (int)$lock->fetchColumn();

            if ($estado !== Habitacion::ESTADO_LIMPIANDO) {
                $db->rollBack();
                throw new Exception("La habitación no está en limpieza.");
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

            $db->commit();
            return ['success' => true, 'message' => 'Limpieza completada. Habitación disponible para venta.'];
        } catch (Exception $e) {
            $db->rollBack();
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