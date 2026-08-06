<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class TransaccionPago extends Model
{
    // Estados de pago según Estados_Pago
    public const ESTADO_PENDIENTE = 1;
    public const ESTADO_PAGO_PARCIAL = 2;
    public const ESTADO_PAGADO_TOTAL = 3;
    public const ESTADO_REEMBOLSADO = 4;

    public function sumIngresosDelDia(?string $fecha = null): float
    {
        $fecha = $fecha ?? date('Y-m-d');

        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(tp.monto_abonado), 0)
                FROM Transacciones_Pago tp
                INNER JOIN Resumen_Pago rp ON tp.id_resumen_pago = rp.id
                WHERE DATE(tp.fecha_hora) = :fecha 
                AND rp.id_estado_pago <> :reembolsado"
            );
            $stmt->execute([
                ':fecha'        => $fecha,
                ':reembolsado'  => self::ESTADO_REEMBOLSADO
            ]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en TransaccionPago::sumIngresosDelDia: " . $e->getMessage());
            throw new Exception("Error al consultar los ingresos del día.");
        }
    }
}
