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
    private ?int $id_motivo_bloqueo = null;
    private int $is_active = self::ACTIVE;
    private ?string $fecha_baja = null;

    // Estados de habitación según Estados_Habitacion
    public const ESTADO_DISPONIBLE = 1;
    public const ESTADO_OCUPADA = 2;
    public const ESTADO_MANTENIMIENTO = 3;
    public const ESTADO_BLOQUEADA = 4;
    public const ESTADO_SUCIA = 5;
    public const ESTADO_LIMPIANDO = 6;

    // Capacidad de personas según tipo de habitación
    public const TIPOS_POR_CAPACIDAD = [
        2 => [1, 4], // Simple + Matrimonial
        3 => [2],    // Doble
        4 => [3],    // Suite
    ];

    public const ACTIVE = 1;
    public const INACTIVE = 0;
    public const STARTING_FLOOR = 1;

    public function getAllWithFilters(?string $search, ?string $status, ?string $type, ?string $floor): array
    {
        $conditions = ["h.is_active = :is_active"];
        $params = ['is_active' => self::ACTIVE];

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
                       h.id_tipo_habitacion, h.id_estado_habitacion,
                       h.id_motivo_bloqueo, mb.descripcion AS motivo_descripcion
                FROM Habitaciones h
                JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                JOIN Estados_Habitacion eh ON h.id_estado_habitacion = eh.id
                LEFT JOIN Motivos_Bloqueo mb ON h.id_motivo_bloqueo = mb.id";

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
            $stmt = $this->db->query("SELECT DISTINCT piso FROM Habitaciones WHERE is_active = 1 ORDER BY piso ASC");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getPisos: " . $e->getMessage());
            return [];
        }
    }

    public function getNextRoomNumber(int $piso): int
    {
        try {
            $stmt = $this->db->prepare("SELECT numero FROM Habitaciones WHERE piso = :piso AND is_active = :is_active ORDER BY numero ASC");
            $stmt->execute([':piso' => $piso, ':is_active' => self::ACTIVE]);
            $existentes = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $expected = (int)($piso * 100 + 1);
            foreach ($existentes as $num) {
                if ((int)$num !== $expected) {
                    return $expected;
                }
                $expected++;
            }
            return $expected;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getNextRoomNumber: " . $e->getMessage());
            return (int)($piso * 100 + 1);
        }
    }

    public function getNextFloorNumber(): int
    {
        try {
            $stmt = $this->db->query("SELECT MAX(piso) FROM Habitaciones WHERE is_active = 1");
            $maxPiso = $stmt->fetchColumn();
            $maxPiso = $maxPiso !== false && $maxPiso !== null ? (int)$maxPiso : -1;
            return $maxPiso < 0 ? self::STARTING_FLOOR : $maxPiso + 1;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getNextFloorNumber: " . $e->getMessage());
            return self::STARTING_FLOOR;
        }
    }

    public function save(Habitacion $habitacion): bool
    {
        try {
            $check = $this->db->prepare("SELECT COUNT(*) FROM Habitaciones WHERE numero = :numero AND is_active = :is_active");
            $check->execute([':numero' => $habitacion->getNumero(), ':is_active' => self::ACTIVE]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El número de habitación ya existe en el sistema.");
            }

            $sql = "INSERT INTO Habitaciones (numero, piso, id_tipo_habitacion, id_estado_habitacion, precio_noche_base, id_motivo_bloqueo)
                    VALUES (:numero, :piso, :id_tipo_habitacion, :id_estado_habitacion, :precio_noche_base, :id_motivo_bloqueo)";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':numero'               => $habitacion->getNumero(),
                ':piso'                 => $habitacion->getPiso(),
                ':id_tipo_habitacion'   => $habitacion->getIdTipoHabitacion(),
                ':id_estado_habitacion' => $habitacion->getIdEstadoHabitacion(),
                ':precio_noche_base'    => $habitacion->getPrecioNocheBase(),
                ':id_motivo_bloqueo'    => $habitacion->getIdMotivoBloqueo()
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
                       h.id_tipo_habitacion, h.id_estado_habitacion,
                       h.id_motivo_bloqueo, mb.descripcion AS motivo_descripcion,
                       h.is_active, h.fecha_baja
                FROM Habitaciones h
                JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                JOIN Estados_Habitacion eh ON h.id_estado_habitacion = eh.id
                LEFT JOIN Motivos_Bloqueo mb ON h.id_motivo_bloqueo = mb.id
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
            $check = $this->db->prepare("SELECT COUNT(*) FROM Habitaciones WHERE numero = :numero AND is_active = :is_active AND id != :id");
            $check->execute([':numero' => $habitacion->getNumero(), ':is_active' => self::ACTIVE, ':id' => $habitacion->getId()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El número de habitación ya está en uso por otra habitación.");
            }

            $sql = "UPDATE Habitaciones
                    SET numero = :numero, piso = :piso,
                        id_tipo_habitacion = :id_tipo_habitacion,
                        id_estado_habitacion = :id_estado_habitacion,
                        precio_noche_base = :precio_noche_base,
                        id_motivo_bloqueo = :id_motivo_bloqueo
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id'                   => $habitacion->getId(),
                ':numero'               => $habitacion->getNumero(),
                ':piso'                 => $habitacion->getPiso(),
                ':id_tipo_habitacion'   => $habitacion->getIdTipoHabitacion(),
                ':id_estado_habitacion' => $habitacion->getIdEstadoHabitacion(),
                ':precio_noche_base'    => $habitacion->getPrecioNocheBase(),
                ':id_motivo_bloqueo'    => $habitacion->getIdMotivoBloqueo()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Habitacion::update: " . $e->getMessage());
            throw new Exception("Error interno al actualizar la habitación.");
        }
    }

    public function deactivate(int $id, ?int $idMotivoBloqueo = null): bool
    {
        try {
            $sql = "UPDATE Habitaciones
                    SET id_estado_habitacion = :nuevo_estado,
                        id_motivo_bloqueo = :id_motivo_bloqueo
                    WHERE id = :id AND id_estado_habitacion = :actual";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':nuevo_estado'      => self::ESTADO_BLOQUEADA,
                ':id_motivo_bloqueo' => $idMotivoBloqueo,
                ':id'                => $id,
                ':actual'            => self::ESTADO_DISPONIBLE
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
                    SET id_estado_habitacion = :nuevo_estado,
                        id_motivo_bloqueo = NULL
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

    public function bajaLogica(int $id): bool
    {
        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare(
                "SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $id]);
            $estado = $lock->fetchColumn();

            if ($estado === false || $estado === null) {
                $this->db->rollBack();
                throw new Exception("La habitación no existe.");
            }

            $estado = (int)$estado;
            if ($estado !== self::ESTADO_DISPONIBLE && $estado !== self::ESTADO_BLOQUEADA) {
                $this->db->rollBack();
                throw new Exception("Solo se pueden dar de baja habitaciones disponibles o bloqueadas.");
            }

            $check = $this->db->prepare(
                "SELECT COUNT(*) FROM Reservas
                 WHERE id_habitacion = :id
                   AND id_estado_reserva IN (:pendiente, :confirmada, :encasa, :noshowpago)"
            );
            $check->execute([
                ':id'         => $id,
                ':pendiente'  => 1,
                ':confirmada' => 2,
                ':encasa'     => 5,
                ':noshowpago' => 7
            ]);

            if ((int)$check->fetchColumn() > 0) {
                $this->db->rollBack();
                throw new Exception("No se puede dar de baja una habitación con reservas activas.");
            }

            $sql = "UPDATE Habitaciones
                    SET is_active = :inactive, fecha_baja = NOW()
                    WHERE id = :id AND is_active = :active";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':inactive' => self::INACTIVE,
                ':id'       => $id,
                ':active'   => self::ACTIVE
            ]);

            $this->db->commit();
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error en Habitacion::bajaLogica: " . $e->getMessage());
            throw $e;
        }
    }

    public function reactivar(int $id): bool
    {
        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare(
                "SELECT is_active, numero FROM Habitaciones WHERE id = :id FOR UPDATE"
            );
            $lock->execute([':id' => $id]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                throw new Exception("La habitación no existe.");
            }

            if ((int)$row['is_active'] === self::ACTIVE) {
                $this->db->rollBack();
                throw new Exception("La habitación ya se encuentra activa.");
            }

            $numero = $row['numero'];
            $check = $this->db->prepare(
                "SELECT COUNT(*) FROM Habitaciones WHERE numero = :numero AND is_active = :active AND id != :id"
            );
            $check->execute([
                ':numero' => $numero,
                ':active' => self::ACTIVE,
                ':id'     => $id
            ]);

            if ((int)$check->fetchColumn() > 0) {
                $this->db->rollBack();
                throw new Exception("No se puede reactivar: el número de habitación ya está asignado a otra activa.");
            }

            $stmt = $this->db->prepare(
                "UPDATE Habitaciones
                 SET is_active = :active, fecha_baja = NULL,
                     id_estado_habitacion = :disponible
                 WHERE id = :id AND is_active = :inactive"
            );
            $stmt->execute([
                ':active'     => self::ACTIVE,
                ':disponible' => self::ESTADO_DISPONIBLE,
                ':id'         => $id,
                ':inactive'   => self::INACTIVE
            ]);

            $this->db->commit();
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error en Habitacion::reactivar: " . $e->getMessage());
            throw $e;
        }
    }

    public function cambiarEstado(int $id, int $nuevoEstado, ?int $soloSiEstaEn = null): bool
    {
        try {
            $sql = "UPDATE Habitaciones SET id_estado_habitacion = :nuevo WHERE id = :id";
            $params = [':nuevo' => $nuevoEstado, ':id' => $id];

            if ($soloSiEstaEn !== null) {
                $sql .= " AND id_estado_habitacion = :actual";
                $params[':actual'] = $soloSiEstaEn;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::cambiarEstado: " . $e->getMessage());
            throw new Exception("Error interno al actualizar el estado de la habitación.");
        }
    }

    public function countByEstado(int $idEstado): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM Habitaciones WHERE id_estado_habitacion = :estado AND is_active = :is_active"
            );
            $stmt->execute([':estado' => $idEstado, ':is_active' => self::ACTIVE]);
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
                 WHERE id_estado_habitacion = ? AND id_tipo_habitacion IN ($placeholders) AND is_active = ?"
            );
            $stmt->execute(array_merge([self::ESTADO_DISPONIBLE], $tiposIds, [self::ACTIVE]));
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

        $sql = "SELECT h.numero, h.piso, th.descripcion AS tipo, eh.descripcion AS estado,
                       h.id_motivo_bloqueo, mb.descripcion AS motivo_descripcion
                FROM Habitaciones h
                JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                JOIN Estados_Habitacion eh ON h.id_estado_habitacion = eh.id
                LEFT JOIN Motivos_Bloqueo mb ON h.id_motivo_bloqueo = mb.id
                WHERE h.id_tipo_habitacion IN ($placeholders) AND h.is_active = ?
                ORDER BY h.numero ASC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge($tiposIds, [self::ACTIVE]));
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

    public static function capacidadParaTipo(int $idTipoHabitacion): int
    {
        foreach (self::TIPOS_POR_CAPACIDAD as $capacidad => $tipos) {
            if (in_array($idTipoHabitacion, $tipos, true)) {
                return $capacidad;
            }
        }
        return 2;
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

    public function getIdMotivoBloqueo(): ?int
    {
        return $this->id_motivo_bloqueo;
    }
    public function setIdMotivoBloqueo(?int $id_motivo_bloqueo): void
    {
        $this->id_motivo_bloqueo = $id_motivo_bloqueo;
    }

    public function getIsActive(): int
    {
        return $this->is_active;
    }
    public function setIsActive(int $is_active): void
    {
        if ($is_active !== 0 && $is_active !== 1) {
            throw new Exception("El valor de is_active debe ser 0 o 1.");
        }
        $this->is_active = $is_active;
    }

    public function getFechaBaja(): ?string
    {
        return $this->fecha_baja;
    }
    public function setFechaBaja(?string $fecha_baja): void
    {
        $this->fecha_baja = $fecha_baja;
    }
}
