<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Consumo extends Model
{
    private int $id = 0;
    private int $id_reserva = 0;
    private string $descripcion = '';
    private float $subtotal = 0.0;
    private int $id_estado = 1; // 1 = Pendiente, 2 = Facturado, 3 = Anulado
    private string $fecha_hora = '';

    public const ESTADO_PENDIENTE = 1;
    public const ESTADO_FACTURADO = 2;
    public const ESTADO_ANULADO = 3;

    public function getAllWithFilters(?string $search, ?string $estado, int $limit, int $offset): array
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $conditions[] = "(c.descripcion LIKE :search OR r.id LIKE :search2)";
            $params['search'] = "%" . $search . "%";
            $params['search2'] = "%" . $search . "%";
        }

        if ($estado !== null && $estado !== '') {
            $conditions[] = "c.id_estado = :estado";
            $params['estado'] = (int)$estado;
        }

        $sql = "SELECT c.id, c.id_reserva, c.descripcion, c.subtotal, c.id_estado,
                       c.fecha_hora, r.id AS reserva_id, r.fecha_entrada, r.fecha_salida,
                       h.numero AS habitacion_numero,
                       cli.nombre AS cliente_nombre, cli.apellido AS cliente_apellido,
                       ep.descripcion AS estado_descripcion
                FROM Consumos c
                JOIN Reservas r ON c.id_reserva = r.id
                JOIN Habitaciones h ON r.id_habitacion = h.id
                JOIN Clientes cli ON r.id_cliente = cli.id
                JOIN Estados_Pago ep ON c.id_estado = ep.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY c.fecha_hora DESC LIMIT :limit OFFSET :offset";

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
            error_log("Error en Consumo::getAllWithFilters: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar consumos.");
        }
    }

    public function countAllWithFilters(?string $search, ?string $estado): int
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $conditions[] = "(c.descripcion LIKE :search OR r.id LIKE :search2)";
            $params['search'] = "%" . $search . "%";
            $params['search2'] = "%" . $search . "%";
        }

        if ($estado !== null && $estado !== '') {
            $conditions[] = "c.id_estado = :estado";
            $params['estado'] = (int)$estado;
        }

        $sql = "SELECT COUNT(*)
                FROM Consumos c
                JOIN Reservas r ON c.id_reserva = r.id
                JOIN Habitaciones h ON r.id_habitacion = h.id
                JOIN Clientes cli ON r.id_cliente = cli.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Consumo::countAllWithFilters: " . $e->getMessage());
            return 0;
        }
    }

    public function findById(int $id): ?array
    {
        try {
            $sql = "SELECT c.*, r.id AS reserva_id, r.fecha_entrada, r.fecha_salida,
                           h.numero AS habitacion_numero,
                           cli.nombre AS cliente_nombre, cli.apellido AS cliente_apellido,
                           ep.descripcion AS estado_descripcion
                    FROM Consumos c
                    JOIN Reservas r ON c.id_reserva = r.id
                    JOIN Habitaciones h ON r.id_habitacion = h.id
                    JOIN Clientes cli ON r.id_cliente = cli.id
                    JOIN Estados_Pago ep ON c.id_estado = ep.id
                    WHERE c.id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Error en Consumo::findById: " . $e->getMessage());
            throw new Exception("Error interno al buscar el consumo.");
        }
    }

    public function save(Consumo $consumo): bool
    {
        try {
            $sql = "INSERT INTO Consumos (id_reserva, descripcion, subtotal, id_estado, fecha_hora)
                    VALUES (:id_reserva, :descripcion, :subtotal, :id_estado, NOW())";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id_reserva' => $consumo->getIdReserva(),
                ':descripcion' => $consumo->getDescripcion(),
                ':subtotal' => $consumo->getSubtotal(),
                ':id_estado' => $consumo->getIdEstado()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Consumo::save: " . $e->getMessage());
            return false;
        }
    }

    public function update(Consumo $consumo): bool
    {
        try {
            $sql = "UPDATE Consumos
                    SET descripcion = :descripcion,
                        subtotal = :subtotal,
                        id_estado = :id_estado
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id' => $consumo->getId(),
                ':descripcion' => $consumo->getDescripcion(),
                ':subtotal' => $consumo->getSubtotal(),
                ':id_estado' => $consumo->getIdEstado()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Consumo::update: " . $e->getMessage());
            return false;
        }
    }

    public function cambiarEstado(int $id, int $nuevoEstado): bool
    {
        try {
            $sql = "UPDATE Consumos SET id_estado = :nuevo WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':nuevo' => $nuevoEstado, ':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Consumo::cambiarEstado: " . $e->getMessage());
            return false;
        }
    }

    public function totalConsumosDe(int $idReserva): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(subtotal), 0) FROM Consumos
                 WHERE id_reserva = ? AND id_estado = ?"
            );
            $stmt->execute([$idReserva, self::ESTADO_FACTURADO]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Consumo::totalConsumosDe: " . $e->getMessage());
            return 0.0;
        }
    }

    public function getId(): int { return $this->id; }
    public function setId(int $id): void { $this->id = $id; }

    public function getIdReserva(): int { return $this->id_reserva; }
    public function setIdReserva(int $id_reserva): void { $this->id_reserva = $id_reserva; }

    public function getDescripcion(): string { return $this->descripcion; }
    public function setDescripcion(string $descripcion): void { $this->descripcion = $descripcion; }

    public function getSubtotal(): float { return $this->subtotal; }
    public function setSubtotal(float $subtotal): void { $this->subtotal = $subtotal; }

    public function getIdEstado(): int { return $this->id_estado; }
    public function setIdEstado(int $id_estado): void { $this->id_estado = $id_estado; }

    public function getFechaHora(): string { return $this->fecha_hora; }
    public function setFechaHora(string $fecha_hora): void { $this->fecha_hora = $fecha_hora; }
}
