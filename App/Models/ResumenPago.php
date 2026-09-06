<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class ResumenPago extends Model
{
    private int $id = 0;
    private int $id_reserva = 0;
    private int $id_estado_pago = 0;
    private float $total = 0.0;
    private float $monto_pagado = 0.0;
    private float $saldo_pendiente = 0.0;

    public const ESTADO_PENDIENTE = 1;
    public const ESTADO_PAGO_PARCIAL = 2;
    public const ESTADO_PAGADO_TOTAL = 3;
    public const ESTADO_REEMBOLSADO = 4;

    public function getByReserva(int $id_reserva): ?ResumenPago
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM Resumen_Pago WHERE id_reserva = :id_reserva");
            $stmt->execute([':id_reserva' => $id_reserva]);

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, ResumenPago::class);
            $resumen = $stmt->fetch();

            return $resumen ?: null;
        } catch (PDOException $e) {
            error_log("Error in getByReserva resumen: " . $e->getMessage());
            throw new Exception("Database error during resumen lookup.");
        }
    }

    public function save(ResumenPago $resumen): bool
    {
        try {
            $check = $this->db->prepare("SELECT COUNT(*) FROM Resumen_Pago WHERE id_reserva = :id_reserva");
            $check->execute([':id_reserva' => $resumen->getIdReserva()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("La reserva ya tiene un resumen de pago asociado.");
            }

            $sql = "INSERT INTO Resumen_Pago (id_reserva, id_estado_pago, monto_total, monto_cobrado, saldo_pendiente)
                    VALUES (:id_reserva, :id_estado_pago, :monto_total, :monto_cobrado, :saldo_pendiente)";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id_reserva'       => $resumen->getIdReserva(),
                ':id_estado_pago'   => $resumen->getIdEstadoPago(),
                ':monto_total'      => $resumen->getTotal(),
                ':monto_cobrado'    => $resumen->getMontoPagado(),
                ':saldo_pendiente'  => $resumen->getSaldoPendiente()
            ]);
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::save: " . $e->getMessage());
            if ($e->getCode() === '23000') {
                throw new Exception("La reserva ya tiene un resumen de pago.");
            }
            throw new Exception("Error interno al guardar el resumen de pago.");
        }
    }

    public function update(ResumenPago $resumen): bool
    {
        try {
            $sql = "UPDATE Resumen_Pago
                    SET id_estado_pago = :id_estado_pago,
                        monto_total    = :monto_total,
                        monto_cobrado  = :monto_cobrado,
                        saldo_pendiente = :saldo_pendiente
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id'                => $resumen->getId(),
                ':id_estado_pago'    => $resumen->getIdEstadoPago(),
                ':monto_total'       => $resumen->getTotal(),
                ':monto_cobrado'     => $resumen->getMontoPagado(),
                ':saldo_pendiente'   => $resumen->getSaldoPendiente()
            ]);
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::update: " . $e->getMessage());
            throw new Exception("Error interno al actualizar el resumen de pago.");
        }
    }

    public function listPagos(?string $search, ?string $estadoPago, int $limit, int $offset): array
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $conditions[] = "(c.nombre LIKE :search OR c.apellido LIKE :search2 OR h.numero LIKE :search3)";
            $params['search'] = "%" . $search . "%";
            $params['search2'] = "%" . $search . "%";
            $params['search3'] = "%" . $search . "%";
        }

        if ($estadoPago !== null && $estadoPago !== '') {
            $conditions[] = "rp.id_estado_pago = :estado_pago";
            $params['estado_pago'] = (int)$estadoPago;
        }

        $sql = "SELECT r.id, r.id_estado_reserva, r.fecha_entrada, r.fecha_salida,
                       c.nombre AS cliente_nombre, c.apellido AS cliente_apellido,
                       h.numero AS habitacion_numero,
                       er.descripcion AS estado_descripcion,
                       rp.id AS id_resumen_pago,
                       rp.id_estado_pago, rp.monto_total, rp.monto_cobrado, rp.saldo_pendiente,
                       ep.descripcion AS estado_pago_descripcion
                FROM Reservas r
                JOIN Clientes c ON r.id_cliente = c.id
                JOIN Habitaciones h ON r.id_habitacion = h.id
                JOIN Estados_Reserva er ON r.id_estado_reserva = er.id
                LEFT JOIN Resumen_Pago rp ON rp.id_reserva = r.id
                LEFT JOIN Estados_Pago ep ON rp.id_estado_pago = ep.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY r.fecha_alta DESC LIMIT :limit OFFSET :offset";

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
            error_log("Error en ResumenPago::listPagos: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar pagos.");
        }
    }

    public function countPagos(?string $search, ?string $estadoPago): int
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $conditions[] = "(c.nombre LIKE :search OR c.apellido LIKE :search2 OR h.numero LIKE :search3)";
            $params['search'] = "%" . $search . "%";
            $params['search2'] = "%" . $search . "%";
            $params['search3'] = "%" . $search . "%";
        }

        if ($estadoPago !== null && $estadoPago !== '') {
            $conditions[] = "rp.id_estado_pago = :estado_pago";
            $params['estado_pago'] = (int)$estadoPago;
        }

        $sql = "SELECT COUNT(*)
                FROM Reservas r
                JOIN Clientes c ON r.id_cliente = c.id
                JOIN Habitaciones h ON r.id_habitacion = h.id
                LEFT JOIN Resumen_Pago rp ON rp.id_reserva = r.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::countPagos: " . $e->getMessage());
            return 0;
        }
    }

    public function getEstadosPago(): array
    {
        try {
            $stmt = $this->db->query("SELECT id, descripcion FROM Estados_Pago ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::getEstadosPago: " . $e->getMessage());
            return [];
        }
    }

    public function reembolsarPorReserva(int $id_reserva): bool
    {
        try {
            $resumen = $this->getByReserva($id_reserva);
            if ($resumen === null) {
                throw new Exception("La reserva no tiene un resumen de pago asociado.");
            }

            $sql = "UPDATE Resumen_Pago
                    SET id_estado_pago = :estado,
                        monto_cobrado  = :cobrado,
                        saldo_pendiente = :saldo
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':estado'  => self::ESTADO_REEMBOLSADO,
                ':cobrado' => 0.0,
                ':saldo'   => 0.0,
                ':id'      => $resumen->getId()
            ]);
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::reembolsarPorReserva: " . $e->getMessage());
            throw new Exception("Error interno al reembolsar el pago.");
        }
    }

    public function getId(): int
    {
        return $this->id;
    }
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getIdReserva(): int
    {
        return $this->id_reserva;
    }
    public function setIdReserva(int $id_reserva): void
    {
        if ($id_reserva <= 0) {
            throw new Exception("La reserva vinculada no es válida.");
        }
        $this->id_reserva = $id_reserva;
    }

    public function getIdEstadoPago(): int
    {
        return $this->id_estado_pago;
    }
    public function setIdEstadoPago(int $id_estado_pago): void
    {
        if ($id_estado_pago <= 0) {
            throw new Exception("El estado de pago no es válido.");
        }
        $this->id_estado_pago = $id_estado_pago;
    }

    public function getTotal(): float
    {
        return $this->total;
    }
    public function setTotal(float $total): void
    {
        if ($total < 0) {
            throw new Exception("El total no puede ser negativo.");
        }
        $this->total = $total;
    }

    public function getMontoPagado(): float
    {
        return $this->monto_pagado;
    }
    public function setMontoPagado(float $monto_pagado): void
    {
        if ($monto_pagado < 0) {
            throw new Exception("El monto pagado no puede ser negativo.");
        }
        $this->monto_pagado = $monto_pagado;
    }

    public function getSaldoPendiente(): float
    {
        return $this->saldo_pendiente;
    }
    public function setSaldoPendiente(float $saldo_pendiente): void
    {
        if ($saldo_pendiente < 0) {
            throw new Exception("El saldo pendiente no puede ser negativo.");
        }
        $this->saldo_pendiente = $saldo_pendiente;
    }
}