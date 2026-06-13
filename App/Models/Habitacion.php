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
        if ($precio_noche_base < 0) {
            throw new Exception("El precio por noche no puede ser negativo.");
        }
        $this->precio_noche_base = $precio_noche_base;
    }
}
