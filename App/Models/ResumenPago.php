<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class ResumenPago extends Model
{
    private int $id;
    private int $id_reserva;
    private int $id_estado_pago;
    private float $total;
    private float $monto_pagado;
    private float $saldo_pendiente;

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