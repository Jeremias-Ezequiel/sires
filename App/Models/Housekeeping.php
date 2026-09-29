<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Housekeeping extends Model
{
    private int $id = 0;
    private int $id_habitacion = 0;
    private ?int $id_empleado_asignado = null;
    private int $id_estado_tarea = 1; // 1 = Pendiente
    private string $tipo_tarea = '';
    private string $fecha_asignada = '';
    private ?string $fecha_completada = null;
    private ?string $observaciones = null;

    public const ESTADO_PENDIENTE = 1;
    public const ESTADO_EN_PROGRESO = 2;
    public const ESTADO_COMPLETADA = 3;
    public const ESTADO_INSPECCIONADA = 4;

    public const TIPO_LIMPIEZA_DIARIA = 'limpieza_diaria';
    public const TIPO_LIMPIEZA_PROFUNDA = 'limpieza_profunda';
    public const TIPO_CAMBIO_SABANAS = 'cambio_sabanas';
    public const TIPO_MANTENIMIENTO = 'mantenimiento';

    public function getAllWithFilters(?string $estado, ?string $tipo, ?string $fecha, int $limit, int $offset): array
    {
        $conditions = [];
        $params = [];

        if ($estado !== null && $estado !== '') {
            $conditions[] = "ht.id_estado_tarea = :estado";
            $params['estado'] = (int)$estado;
        }

        if ($tipo !== null && $tipo !== '') {
            $conditions[] = "ht.tipo_tarea = :tipo";
            $params['tipo'] = $tipo;
        }

        if ($fecha !== null && $fecha !== '') {
            $conditions[] = "ht.fecha_asignada = :fecha";
            $params['fecha'] = $fecha;
        }

        $sql = "SELECT ht.id, ht.id_habitacion, ht.id_empleado_asignado, ht.id_estado_tarea,
                       ht.tipo_tarea, ht.fecha_asignada, ht.fecha_completada, ht.observaciones,
                       h.numero AS habitacion_numero, h.piso AS habitacion_piso,
                       u.nombre AS empleado_nombre, u.apellido AS empleado_apellido,
                       he.descripcion AS estado_descripcion
                FROM Housekeeping_Tareas ht
                JOIN Habitaciones h ON ht.id_habitacion = h.id
                LEFT JOIN Usuarios u ON ht.id_empleado_asignado = u.id
                JOIN Housekeeping_Estados he ON ht.id_estado_tarea = he.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY ht.fecha_asignada DESC, ht.id DESC LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::getAllWithFilters: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar tareas de housekeeping.");
        }
    }

    public function countAllWithFilters(?string $estado, ?string $tipo, ?string $fecha): int
    {
        $conditions = [];
        $params = [];

        if ($estado !== null && $estado !== '') {
            $conditions[] = "ht.id_estado_tarea = :estado";
            $params['estado'] = (int)$estado;
        }

        if ($tipo !== null && $tipo !== '') {
            $conditions[] = "ht.tipo_tarea = :tipo";
            $params['tipo'] = $tipo;
        }

        if ($fecha !== null && $fecha !== '') {
            $conditions[] = "ht.fecha_asignada = :fecha";
            $params['fecha'] = $fecha;
        }

        $sql = "SELECT COUNT(*)
                FROM Housekeeping_Tareas ht
                JOIN Habitaciones h ON ht.id_habitacion = h.id
                LEFT JOIN Usuarios u ON ht.id_empleado_asignado = u.id
                JOIN Housekeeping_Estados he ON ht.id_estado_tarea = he.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::countAllWithFilters: " . $e->getMessage());
            return 0;
        }
    }

    public function getEstadosTarea(): array
    {
        try {
            $stmt = $this->db->query("SELECT id, descripcion FROM Housekeeping_Estados ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::getEstadosTarea: " . $e->getMessage());
            return [];
        }
    }

    public function getTiposTarea(): array
    {
        return [
            self::TIPO_LIMPIEZA_DIARIA => 'Limpieza diaria',
            self::TIPO_LIMPIEZA_PROFUNDA => 'Limpieza profunda',
            self::TIPO_CAMBIO_SABANAS => 'Cambio de sábanas',
            self::TIPO_MANTENIMIENTO => 'Mantenimiento',
        ];
    }

    public function findById(int $id): ?array
    {
        try {
            $sql = "SELECT ht.*, h.numero AS habitacion_numero, h.piso AS habitacion_piso,
                           u.nombre AS empleado_nombre, u.apellido AS empleado_apellido,
                           he.descripcion AS estado_descripcion
                    FROM Housekeeping_Tareas ht
                    JOIN Habitaciones h ON ht.id_habitacion = h.id
                    LEFT JOIN Usuarios u ON ht.id_empleado_asignado = u.id
                    JOIN Housekeeping_Estados he ON ht.id_estado_tarea = he.id
                    WHERE ht.id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::findById: " . $e->getMessage());
            throw new Exception("Error interno al buscar la tarea.");
        }
    }

    public function save(Housekeeping $tarea): bool
    {
        try {
            $sql = "INSERT INTO Housekeeping_Tareas (id_habitacion, id_empleado_asignado, id_estado_tarea, tipo_tarea, fecha_asignada, observaciones)
                    VALUES (:id_habitacion, :id_empleado_asignado, :id_estado_tarea, :tipo_tarea, :fecha_asignada, :observaciones)";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id_habitacion'        => $tarea->getIdHabitacion(),
                ':id_empleado_asignado' => $tarea->getIdEmpleadoAsignado(),
                ':id_estado_tarea'      => $tarea->getIdEstadoTarea(),
                ':tipo_tarea'           => $tarea->getTipoTarea(),
                ':fecha_asignada'       => $tarea->getFechaAsignada(),
                ':observaciones'        => $tarea->getObservaciones()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::save: " . $e->getMessage());
            throw new Exception("Error interno al guardar la tarea.");
        }
    }

    public function update(Housekeeping $tarea): bool
    {
        try {
            $sql = "UPDATE Housekeeping_Tareas
                    SET id_habitacion = :id_habitacion,
                        id_empleado_asignado = :id_empleado_asignado,
                        id_estado_tarea = :id_estado_tarea,
                        tipo_tarea = :tipo_tarea,
                        fecha_asignada = :fecha_asignada,
                        fecha_completada = :fecha_completada,
                        observaciones = :observaciones
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id'                  => $tarea->getId(),
                ':id_habitacion'       => $tarea->getIdHabitacion(),
                ':id_empleado_asignado'=> $tarea->getIdEmpleadoAsignado(),
                ':id_estado_tarea'     => $tarea->getIdEstadoTarea(),
                ':tipo_tarea'          => $tarea->getTipoTarea(),
                ':fecha_asignada'      => $tarea->getFechaAsignada(),
                ':fecha_completada'    => $tarea->getFechaCompletada(),
                ':observaciones'       => $tarea->getObservaciones()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::update: " . $e->getMessage());
            throw new Exception("Error interno al actualizar la tarea.");
        }
    }

    public function cambiarEstado(int $id, int $nuevoEstado): bool
    {
        try {
            $sql = "UPDATE Housekeeping_Tareas SET id_estado_tarea = :nuevo WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':nuevo' => $nuevoEstado, ':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::cambiarEstado: " . $e->getMessage());
            throw new Exception("Error interno al cambiar el estado de la tarea.");
        }
    }

    public function completar(int $id): bool
    {
        try {
            $sql = "UPDATE Housekeeping_Tareas SET id_estado_tarea = :estado, fecha_completada = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':estado' => self::ESTADO_COMPLETADA, ':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::completar: " . $e->getMessage());
            throw new Exception("Error interno al completar la tarea.");
        }
    }

    public function inspeccionar(int $id): bool
    {
        try {
            $sql = "UPDATE Housekeeping_Tareas SET id_estado_tarea = :estado WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':estado' => self::ESTADO_INSPECCIONADA, ':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::inspeccionar: " . $e->getMessage());
            throw new Exception("Error interno al inspeccionar la tarea.");
        }
    }

    public function getInsumos(): array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM Housekeeping_Insumos ORDER BY descripcion ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::getInsumos: " . $e->getMessage());
            return [];
        }
    }

    public function getInsumosBajoStock(): array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM Housekeeping_Insumos WHERE stock_actual <= stock_minimo ORDER BY stock_actual ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::getInsumosBajoStock: " . $e->getMessage());
            return [];
        }
    }

    public function actualizarStock(int $idInsumo, int $cantidad): bool
    {
        try {
            $sql = "UPDATE Housekeeping_Insumos SET stock_actual = stock_actual + :cantidad WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':cantidad' => $cantidad, ':id' => $idInsumo]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Housekeeping::actualizarStock: " . $e->getMessage());
            throw new Exception("Error interno al actualizar el stock.");
        }
    }

    // Getters y Setters
    public function getId(): int { return $this->id; }
    public function setId(int $id): void { $this->id = $id; }

    public function getIdHabitacion(): int { return $this->id_habitacion; }
    public function setIdHabitacion(int $id_habitacion): void { $this->id_habitacion = $id_habitacion; }

    public function getIdEmpleadoAsignado(): ?int { return $this->id_empleado_asignado; }
    public function setIdEmpleadoAsignado(?int $id_empleado_asignado): void { $this->id_empleado_asignado = $id_empleado_asignado; }

    public function getIdEstadoTarea(): int { return $this->id_estado_tarea; }
    public function setIdEstadoTarea(int $id_estado_tarea): void { $this->id_estado_tarea = $id_estado_tarea; }

    public function getTipoTarea(): string { return $this->tipo_tarea; }
    public function setTipoTarea(string $tipo_tarea): void { $this->tipo_tarea = $tipo_tarea; }

    public function getFechaAsignada(): string { return $this->fecha_asignada; }
    public function setFechaAsignada(string $fecha_asignada): void { $this->fecha_asignada = $fecha_asignada; }

    public function getFechaCompletada(): ?string { return $this->fecha_completada; }
    public function setFechaCompletada(?string $fecha_completada): void { $this->fecha_completada = $fecha_completada; }

    public function getObservaciones(): ?string { return $this->observaciones; }
    public function setObservaciones(?string $observaciones): void { $this->observaciones = $observaciones; }
}
