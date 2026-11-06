<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/** Tabla Comprobantes (migración 015): comprobantes PDF emitidos, con su copia de datos. */
class ComprobanteModel extends Model
{
    /** Último número emitido de un tipo y punto de venta, con bloqueo hasta el fin de la transacción. */
    public function ultimoNumeroParaActualizar(string $tipo, int $puntoVenta): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT MAX(numero) FROM Comprobantes WHERE tipo = :tipo AND punto_venta = :pv FOR UPDATE"
        );
        $stmt->execute([':tipo' => $tipo, ':pv' => $puntoVenta]);
        $n = $stmt->fetchColumn();
        return $n === null || $n === false ? null : (int)$n;
    }

    /** @param array<string, mixed> $datos */
    public function insertar(string $tipo, int $puntoVenta, int $numero, int $idReserva, ?int $idTransaccion,
                             float $monto, ?int $emitidoPor, array $datos): int
    {
        $this->db->prepare(
            "INSERT INTO Comprobantes (tipo, punto_venta, numero, id_reserva, id_transaccion, monto, fecha_emision, emitido_por, datos)
             VALUES (:tipo, :pv, :numero, :reserva, :tx, :monto, :fecha, :usuario, :datos)"
        )->execute([
            ':tipo' => $tipo, ':pv' => $puntoVenta, ':numero' => $numero, ':reserva' => $idReserva,
            ':tx' => $idTransaccion, ':monto' => round($monto, 2), ':fecha' => date('Y-m-d H:i:s'),
            ':usuario' => $emitidoPor, ':datos' => json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM Comprobantes WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null */
    public function porTransaccion(int $idTransaccion): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM Comprobantes WHERE id_transaccion = :id");
        $stmt->execute([':id' => $idTransaccion]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> Sin la columna datos, los más nuevos primero. */
    public function porReserva(int $idReserva): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.id, c.tipo, c.punto_venta, c.numero, c.id_transaccion, c.monto, c.fecha_emision,
                    CONCAT(u.nombre, ' ', u.apellido) AS emitido_por_nombre
             FROM Comprobantes c
             LEFT JOIN Usuarios u ON u.id = c.emitido_por
             WHERE c.id_reserva = :id
             ORDER BY c.fecha_emision DESC, c.id DESC"
        );
        $stmt->execute([':id' => $idReserva]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
