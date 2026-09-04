<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Habitacion extends Model
{
    private int $id;
    private int $numero;
    private int $piso;
    private int $id_tipo_habitacion;
    private int $id_estado_habitacion;
    private float $precio_noche_base;

    // Estados de habitación según Estados_Habitacion
    public const ESTADO_DISPONIBLE = 1;
    public const ESTADO_OCUPADA = 2;
    public const ESTADO_MANTENIMIENTO = 3;
    public const ESTADO_BLOQUEADA = 4;

    // Regla de negocio: tipos de habitación según capacidad de personas
    public const TIPOS_POR_CAPACIDAD = [
        2 => [1, 4], // Simple + Matrimonial
        3 => [2],    // Doble
        4 => [3],    // Suite
    ];

    public function getAllWithFilters(?string $search, ?string $status, ?string $type, ?string $floor): array
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $conditions[] = "h.numero LIKE :search";
            $params['search'] = "%" . $search . "%";
        }

        if ($status !== null && $status !== '') {
            $conditions[] = "h.id_estado_habitacion = :status";
            $params['status'] = (int)$status;
        }

        if ($type !== null && $type !== '') {
            $conditions[] = "h.id_tipo_habitacion = :type";
            $params['type'] = (int)$type;
        }

        if ($floor !== null && $floor !== '') {
            $conditions[] = "h.piso = :floor";
            $params['floor'] = (int)$floor;
        }

        $sql = "SELECT h.id, h.numero, h.piso, h.precio_noche_base,
                       th.descripcion AS tipo, eh.descripcion AS estado,
                       h.id_tipo_habitacion, h.id_estado_habitacion
                FROM Habitaciones h
                JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                JOIN Estados_Habitacion eh ON h.id_estado_habitacion = eh.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY h.numero ASC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getAllWithFilters: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar habitaciones.");
        }
    }

    public function getTiposHabitacion(): array
    {
        try {
            $stmt = $this->db->query("SELECT id, descripcion FROM Tipos_Habitacion ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getTiposHabitacion: " . $e->getMessage());
            return [];
        }
    }

    public function getEstadosHabitacion(): array
    {
        try {
            $stmt = $this->db->query("SELECT id, descripcion FROM Estados_Habitacion ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getEstadosHabitacion: " . $e->getMessage());
            return [];
        }
    }

    public function getPisos(): array
    {
        try {
            $stmt = $this->db->query("SELECT DISTINCT piso FROM Habitaciones ORDER BY piso ASC");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getPisos: " . $e->getMessage());
            return [];
        }
    }

    public function save(Habitacion $habitacion): bool
    {
        try {
            $check = $this->db->prepare("SELECT COUNT(*) FROM Habitaciones WHERE numero = :numero");
            $check->execute([':numero' => $habitacion->getNumero()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El número de habitación ya existe en el sistema.");
            }

            $sql = "INSERT INTO Habitaciones (numero, piso, id_tipo_habitacion, id_estado_habitacion, precio_noche_base)
                    VALUES (:numero, :piso, :id_tipo_habitacion, :id_estado_habitacion, :precio_noche_base)";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':numero'               => $habitacion->getNumero(),
                ':piso'                 => $habitacion->getPiso(),
                ':id_tipo_habitacion'   => $habitacion->getIdTipoHabitacion(),
                ':id_estado_habitacion' => $habitacion->getIdEstadoHabitacion(),
                ':precio_noche_base'    => $habitacion->getPrecioNocheBase()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Habitacion::save: " . $e->getMessage());
            throw new Exception("Error interno al registrar la habitación.");
        }
    }

    public function findById(int $id): ?array
    {
        try {
            $sql = "SELECT h.id, h.numero, h.piso, h.precio_noche_base,
                           th.descripcion AS tipo, eh.descripcion AS estado,
                           h.id_tipo_habitacion, h.id_estado_habitacion
                    FROM Habitaciones h
                    JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                    JOIN Estados_Habitacion eh ON h.id_estado_habitacion = eh.id
                    WHERE h.id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::findById: " . $e->getMessage());
            throw new Exception("Error interno al buscar la habitación.");
        }
    }

    public function update(Habitacion $habitacion): bool
    {
        try {
            $check = $this->db->prepare("SELECT COUNT(*) FROM Habitaciones WHERE numero = :numero AND id != :id");
            $check->execute([':numero' => $habitacion->getNumero(), ':id' => $habitacion->getId()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El número de habitación ya está en uso por otra habitación.");
            }

            $sql = "UPDATE Habitaciones
                    SET numero = :numero, piso = :piso,
                        id_tipo_habitacion = :id_tipo_habitacion,
                        id_estado_habitacion = :id_estado_habitacion,
                        precio_noche_base = :precio_noche_base
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id'                   => $habitacion->getId(),
                ':numero'               => $habitacion->getNumero(),
                ':piso'                 => $habitacion->getPiso(),
                ':id_tipo_habitacion'   => $habitacion->getIdTipoHabitacion(),
                ':id_estado_habitacion' => $habitacion->getIdEstadoHabitacion(),
                ':precio_noche_base'    => $habitacion->getPrecioNocheBase()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Habitacion::update: " . $e->getMessage());
            throw new Exception("Error interno al actualizar la habitación.");
        }
    }

    public function deactivate(int $id): bool
    {
        try {
            $sql = "UPDATE Habitaciones
                    SET id_estado_habitacion = :nuevo_estado
                    WHERE id = :id AND id_estado_habitacion = :actual";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':nuevo_estado' => self::ESTADO_BLOQUEADA,
                ':id'           => $id,
                ':actual'       => self::ESTADO_DISPONIBLE
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::deactivate: " . $e->getMessage());
            throw new Exception("Error interno en la base de datos.");
        }
    }

    public function activate(int $id): bool
    {
        try {
            $sql = "UPDATE Habitaciones
                    SET id_estado_habitacion = :nuevo_estado
                    WHERE id = :id AND id_estado_habitacion = :actual";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':nuevo_estado' => self::ESTADO_DISPONIBLE,
                ':id'           => $id,
                ':actual'       => self::ESTADO_BLOQUEADA
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::activate: " . $e->getMessage());
            throw new Exception("Error interno en la base de datos.");
        }
    }

    public function countByEstado(int $idEstado): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM Habitaciones WHERE id_estado_habitacion = :estado"
            );
            $stmt->execute([':estado' => $idEstado]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Habitacion::countByEstado: " . $e->getMessage());
            throw new Exception("Error al consultar las habitaciones por estado.");
        }
    }

    public function countDisponiblesPorCapacidad(int $capacidad): int
    {
        $tiposIds = $this->tiposPorCapacidad($capacidad);
        $placeholders = implode(',', array_fill(0, count($tiposIds), '?'));

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM Habitaciones
                 WHERE id_estado_habitacion = ? AND id_tipo_habitacion IN ($placeholders)"
            );
            $stmt->execute(array_merge([self::ESTADO_DISPONIBLE], $tiposIds));
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Habitacion::countDisponiblesPorCapacidad: " . $e->getMessage());
            throw new Exception("Error al calcular la disponibilidad por capacidad.");
        }
    }

    public function getHabitacionesPorCapacidad(int $capacidad): array
    {
        $tiposIds = $this->tiposPorCapacidad($capacidad);
        $placeholders = implode(',', array_fill(0, count($tiposIds), '?'));

        $sql = "SELECT h.numero, h.piso, th.descripcion AS tipo, eh.descripcion AS estado
                FROM Habitaciones h
                JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                JOIN Estados_Habitacion eh ON h.id_estado_habitacion = eh.id
                WHERE h.id_tipo_habitacion IN ($placeholders)
                ORDER BY h.numero ASC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($tiposIds);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getHabitacionesPorCapacidad: " . $e->getMessage());
            throw new Exception("Error al obtener las habitaciones por capacidad.");
        }
    }

    private function tiposPorCapacidad(int $capacidad): array
    {
        return self::TIPOS_POR_CAPACIDAD[$capacidad] ?? self::TIPOS_POR_CAPACIDAD[2];
    }

    // =====================================================================
    // GETTERS Y SETTERS
    // =====================================================================

    public function getId(): int
    {
        return $this->id;
    }
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }
    public function setNumero(int $numero): void
    {
        if ($numero <= 0) {
            throw new Exception("El número de habitación debe ser mayor a 0.");
        }
        $this->numero = $numero;
    }

    public function getPiso(): int
    {
        return $this->piso;
    }
    public function setPiso(int $piso): void
    {
        if ($piso < 0) {
            throw new Exception("El piso no puede ser negativo.");
        }
        $this->piso = $piso;
    }

    public function getIdTipoHabitacion(): int
    {
        return $this->id_tipo_habitacion;
    }
    public function setIdTipoHabitacion(int $id_tipo_habitacion): void
    {
        if ($id_tipo_habitacion <= 0) {
            throw new Exception("El tipo de habitación no es válido.");
        }
        $this->id_tipo_habitacion = $id_tipo_habitacion;
    }

    public function getIdEstadoHabitacion(): int
    {
        return $this->id_estado_habitacion;
    }
    public function setIdEstadoHabitacion(int $id_estado_habitacion): void
    {
        if ($id_estado_habitacion <= 0) {
            throw new Exception("El estado de habitación no es válido.");
        }
        $this->id_estado_habitacion = $id_estado_habitacion;
    }

    public function getPrecioNocheBase(): float
    {
        return $this->precio_noche_base;
    }
    public function setPrecioNocheBase(float $precio_noche_base): void
    {
        if ($precio_noche_base <= 0) {
            throw new Exception("El precio por noche debe ser mayor a 0.");
        }
        $this->precio_noche_base = $precio_noche_base;
    }
}
