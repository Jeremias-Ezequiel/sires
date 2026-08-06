<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Reserva extends Model
{
    // Estados de reserva según Estados_Reserva
    public const ESTADO_PENDIENTE = 1;
    public const ESTADO_CONFIRMADA = 2;
    public const ESTADO_CANCELADA = 3;
    public const ESTADO_FINALIZADA = 4;

    public function countReservasHoy(?string $fecha = null): int
    {
        $fecha = $fecha ?? date('Y-m-d');

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM Reservas
                 WHERE fecha_entrada = :fecha AND id_estado_reserva <> :cancelada"
            );
            $stmt->execute([
                ':fecha'     => $fecha,
                ':cancelada' => self::ESTADO_CANCELADA
            ]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Reserva::countReservasHoy: " . $e->getMessage());
            throw new Exception("Error al consultar las reservas del día.");
        }
    }
}
