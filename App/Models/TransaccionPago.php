<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class TransaccionPago extends Model
{
    private int $id;
    private int $id_resumen_pago;
    private int $id_metodo_pago;
    private float $monto_abonado;
    private string $fecha_hora;
    private int $registrado_por;

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

    public function save(TransaccionPago $transaccion): bool
    {
        try {
            if ($transaccion->getMontoAbonado() <= 0) {
                throw new Exception("El monto a abonar debe ser mayor a 0.");
            }

            $sql = "INSERT INTO Transacciones_Pago (id_resumen_pago, id_metodo_pago, monto_abonado, registrado_por)
                    VALUES (:id_resumen_pago, :id_metodo_pago, :monto_abonado, :registrado_por)";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id_resumen_pago'  => $transaccion->getIdResumenPago(),
                ':id_metodo_pago'   => $transaccion->getIdMetodoPago(),
                ':monto_abonado'    => $transaccion->getMontoAbonado(),
                ':registrado_por'   => $transaccion->getRegistradoPor()
            ]);
        } catch (PDOException $e) {
            error_log("Error en TransaccionPago::save: " . $e->getMessage());
            throw new Exception("Error interno al registrar la transacción.");
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

    public function getIdResumenPago(): int
    {
        return $this->id_resumen_pago;
    }
    public function setIdResumenPago(int $id_resumen_pago): void
    {
        if ($id_resumen_pago <= 0) {
            throw new Exception("El resumen de pago no es válido.");
        }
        $this->id_resumen_pago = $id_resumen_pago;
    }

    public function getIdMetodoPago(): int
    {
        return $this->id_metodo_pago;
    }
    public function setIdMetodoPago(int $id_metodo_pago): void
    {
        if ($id_metodo_pago <= 0) {
            throw new Exception("El método de pago no es válido.");
        }
        $this->id_metodo_pago = $id_metodo_pago;
    }

    public function getMontoAbonado(): float
    {
        return $this->monto_abonado;
    }
    public function setMontoAbonado(float $monto_abonado): void
    {
        if ($monto_abonado <= 0) {
            throw new Exception("El monto a abonar debe ser mayor a 0.");
        }
        $this->monto_abonado = $monto_abonado;
    }

    public function getFechaHora(): string
    {
        return $this->fecha_hora;
    }
    public function setFechaHora(string $fecha_hora): void
    {
        $this->fecha_hora = $fecha_hora;
    }

    public function getRegistradoPor(): int
    {
        return $this->registrado_por;
    }
    public function setRegistradoPor(int $registrado_por): void
    {
        if ($registrado_por <= 0) {
            throw new Exception("El usuario registrador no es válido.");
        }
        $this->registrado_por = $registrado_por;
    }
}
