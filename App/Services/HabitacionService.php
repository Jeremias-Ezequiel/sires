<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use App\Models\Habitacion;
use App\Models\MotivoBloqueo;
use PDO;
use PDOException;

class HabitacionService
{
    private Habitacion $habitacionModel;

    public function __construct()
    {
        $this->habitacionModel = new Habitacion();
    }

    public function altaIndividual(int $piso, int $idTipo, float $precioNoche): array
    {
        if ($piso < 0) {
            throw new Exception("El piso no puede ser negativo.");
        }
        if ($idTipo <= 0) {
            throw new Exception("Debe seleccionar un tipo de habitación.");
        }
        if ($precioNoche <= 0) {
            throw new Exception("El precio por noche debe ser mayor a 0.");
        }

        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT MAX(numero) FROM Habitaciones WHERE piso = :piso AND is_active = 1 FOR UPDATE"
            );
            $lock->execute([':piso' => $piso]);
            $maxNumero = $lock->fetchColumn();
            $maxNumero = $maxNumero !== false && $maxNumero !== null ? (int)$maxNumero : ($piso * 100);

            $numero = $maxNumero + 1;

            $check = $db->prepare(
                "SELECT COUNT(*) FROM Habitaciones WHERE numero = :numero AND is_active = 1"
            );
            $check->execute([':numero' => $numero]);
            if ((int)$check->fetchColumn() > 0) {
                $db->rollBack();
                throw new Exception("El número de habitación " . $numero . " ya existe.");
            }

            $sql = "INSERT INTO Habitaciones (numero, piso, id_tipo_habitacion, id_estado_habitacion, precio_noche_base)
                    VALUES (:numero, :piso, :id_tipo, :estado, :precio)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':numero' => $numero,
                ':piso'   => $piso,
                ':id_tipo' => $idTipo,
                ':estado' => Habitacion::ESTADO_DISPONIBLE,
                ':precio' => $precioNoche
            ]);

            $db->commit();
            return ['success' => true, 'message' => 'Habitación N° ' . $numero . ' registrada exitosamente.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function altaMasiva(int $piso, int $idTipo, float $precioNoche, int $desde, int $hasta): array
    {
        if ($piso < 0) {
            throw new Exception("El piso no puede ser negativo.");
        }
        if ($idTipo <= 0) {
            throw new Exception("Debe seleccionar un tipo de habitación.");
        }
        if ($precioNoche <= 0) {
            throw new Exception("El precio por noche debe ser mayor a 0.");
        }
        if ($desde <= 0 || $hasta <= 0 || $hasta < $desde) {
            throw new Exception("El rango numérico no es válido.");
        }

        $total = $hasta - $desde + 1;
        if ($total > 100) {
            throw new Exception("No se pueden generar más de 100 habitaciones por lote.");
        }

        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT numero FROM Habitaciones WHERE piso = :piso AND is_active = 1 FOR UPDATE"
            );
            $lock->execute([':piso' => $piso]);
            $existentes = $lock->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $numerosGenerar = [];
            for ($i = $desde; $i <= $hasta; $i++) {
                $numero = $piso * 100 + $i;
                if (in_array($numero, $existentes, true)) {
                    $db->rollBack();
                    throw new Exception("La habitación N° " . $numero . " ya existe en el sistema.");
                }
                $numerosGenerar[] = $numero;
            }

            $sql = "INSERT INTO Habitaciones (numero, piso, id_tipo_habitacion, id_estado_habitacion, precio_noche_base)
                    VALUES (:numero, :piso, :id_tipo, :estado, :precio)";
            foreach ($numeroStr as $numeroStr) {
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':numero' => $numeroStr,
                    ':piso'   => $piso,
                    ':id_tipo' => $idTipo,
                    ':estado' => Habitacion::ESTADO_DISPONIBLE,
                    ':precio' => $precioNoche
                ]);
            }

            $db->commit();
            return [
                'success' => true,
                'message' => $total . ' habitaciones generadas exitosamente (N° ' . $numerosGenerar[0] . ' al ' . $numerosGenerar[count($numerosGenerar) - 1] . ').'
            ];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function bloquear(int $id, ?int $idMotivoBloqueo = null): array
    {
        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $id]);
            $estado = (int)$lock->fetchColumn();

            if ($estado !== Habitacion::ESTADO_DISPONIBLE) {
                $db->rollBack();
                throw new Exception("Solo se pueden bloquear habitaciones disponibles.");
            }

            $sql = "UPDATE Habitaciones
                    SET id_estado_habitacion = :nuevo,
                        id_motivo_bloqueo = :motivo
                    WHERE id = :id AND id_estado_habitacion = :actual";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':nuevo'  => Habitacion::ESTADO_BLOQUEADA,
                ':motivo' => $idMotivoBloqueo,
                ':id'     => $id,
                ':actual' => Habitacion::ESTADO_DISPONIBLE
            ]);

            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                throw new Exception("No se pudo bloquear la habitación.");
            }

            $db->commit();
            $msg = $idMotivoBloqueo !== null ? 'bloqueada por motivo de mantenimiento' : 'bloqueada';
            return ['success' => true, 'message' => 'Habitación ' . $msg . ' exitosamente.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function desbloquear(int $id): array
    {
        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $id]);
            $estado = (int)$lock->fetchColumn();

            if ($estado !== Habitacion::ESTADO_BLOQUEADA) {
                $db->rollBack();
                throw new Exception("Solo se pueden desbloquear habitaciones bloqueadas.");
            }

            $stmt = $db->prepare(
                "UPDATE Habitaciones
                 SET id_estado_habitacion = :nuevo, id_motivo_bloqueo = NULL
                 WHERE id = :id AND id_estado_habitacion = :actual"
            );
            $stmt->execute([
                ':nuevo'  => Habitacion::ESTADO_DISPONIBLE,
                ':id'     => $id,
                ':actual' => Habitacion::ESTADO_BLOQUEADA
            ]);

            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                throw new Exception("No se pudo desbloquear la habitación.");
            }

            $db->commit();
            return ['success' => true, 'message' => 'Habitación desbloqueada exitosamente.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function ponerEnMantenimiento(int $id): array
    {
        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();

        try {
            $lock = $db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $id]);
            $estado = (int)$lock->fetchColumn();

            if ($estado !== Habitacion::ESTADO_DISPONIBLE && $estado !== Habitacion::ESTADO_OCUPADA) {
                $db->rollBack();
                throw new Exception("La habitación debe estar disponible u ocupada para ponerla en mantenimiento.");
            }

            $stmt = $db->prepare(
                "UPDATE Habitaciones SET id_estado_habitacion = :nuevo
                 WHERE id = :id AND id_estado_habitacion = :actual"
            );
            $stmt->execute([
                ':nuevo'  => Habitacion::ESTADO_MANTENIMIENTO,
                ':id'     => $id,
                ':actual' => $estado
            ]);

            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                throw new Exception("No se pudo poner la habitación en mantenimiento.");
            }

            $db->commit();
            return ['success' => true, 'message' => 'Habitación puesta en mantenimiento.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function salirDeMantenimiento(int $id): array
    {
        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare(
                "UPDATE Habitaciones SET id_estado_habitacion = :nuevo, id_motivo_bloqueo = NULL
                 WHERE id = :id AND id_estado_habitacion = :actual"
            );
            $stmt->execute([
                ':nuevo'  => Habitacion::ESTADO_DISPONIBLE,
                ':id'     => $id,
                ':actual' => Habitacion::ESTADO_MANTENIMIENTO
            ]);

            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                throw new Exception("La habitación no está en mantenimiento.");
            }

            $db->commit();
            return ['success' => true, 'message' => 'Habitación disponible nuevamente.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function editar(int $id, int $numero, int $piso, int $idTipo, int $idEstado, float $precioNoche): array
    {
        if ($numero <= 0) {
            throw new Exception("El número de habitación debe ser mayor a 0.");
        }

        $db = $this->habitacionModel->getConnection();
        $db->beginTransaction();

        try {
            $check = $db->prepare(
                "SELECT COUNT(*) FROM Habitaciones WHERE numero = :numero AND is_active = 1 AND id != :id"
            );
            $check->execute([':numero' => $numero, ':id' => $id]);
            if ((int)$check->fetchColumn() > 0) {
                $db->rollBack();
                throw new Exception("El número de habitación ya está en uso por otra habitación.");
            }

            $sql = "UPDATE Habitaciones
                    SET numero = :numero, piso = :piso,
                        id_tipo_habitacion = :tipo,
                        id_estado_habitacion = :estado,
                        precio_noche_base = :precio
                    WHERE id = :id";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':numero' => $numero,
                ':piso'   => $piso,
                ':tipo'   => $idTipo,
                ':estado' => $idEstado,
                ':precio' => $precioNoche,
                ':id'     => $id
            ]);

            $db->commit();
            return ['success' => true, 'message' => 'Habitación actualizada exitosamente.'];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}