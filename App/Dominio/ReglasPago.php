<?php

declare(strict_types=1);

namespace App\Dominio;

use App\Models\EstadoPago;

/**
 * Reglas de pago sin acceso a la base: seña mínima, estado del resumen, retención y late fee.
 * La usan PagoService y ReservaService; los EVENTs corte_pagos, cargo_late_checkout
 * y liberacion_no_show replican la misma lógica en SQL.
 */
final class ReglasPago
{
    /** Monto de la seña mínima, redondeado a centavos. */
    public static function senaMinima(float $total, float $pct): float
    {
        return round($total * $pct / 100, 2);
    }

    /** Si lo cobrado alcanza la seña mínima para confirmar. */
    public static function alcanzaSena(float $cobrado, float $total, float $pct): bool
    {
        return $cobrado >= self::senaMinima($total, $pct);
    }

    /** Estado del resumen después de registrar un pago. */
    public static function estadoTrasPago(float $nuevoSaldo, float $nuevoMontoPagado): int
    {
        if ($nuevoSaldo <= 0) {
            return EstadoPago::PAGADO_TOTAL;
        }
        return $nuevoMontoPagado > 0 ? EstadoPago::PAGO_PARCIAL : EstadoPago::PENDIENTE;
    }

    /**
     * Estado del resumen después de recalcular el total.
     * Si se pasa $estadoActual y es Reembolsado o Retenido, se conserva.
     */
    public static function estadoTrasRecalculo(float $nuevoSaldo, float $montoCobrado, ?int $estadoActual = null): int
    {
        if (in_array($estadoActual, [EstadoPago::REEMBOLSADO, EstadoPago::RETENIDO], true)) {
            return $estadoActual;
        }
        if ($nuevoSaldo <= 0 && $montoCobrado > 0) {
            return EstadoPago::PAGADO_TOTAL;
        }
        return $montoCobrado > 0 ? EstadoPago::PAGO_PARCIAL : EstadoPago::PENDIENTE;
    }

    /**
     * Cuánto retiene el hotel de lo cobrado y en qué estado queda el pago.
     * El retenido tiene tope en lo cobrado (y no es negativo). Si se cobró más, el pago
     * queda A Reembolsar; si no, Retenido. Sin cobros devuelve null (no se modifica nada).
     *
     * @return array{retenido: float, estado: int}|null
     */
    public static function retencion(float $montoCobrado, float $montoRetenido): ?array
    {
        if ($montoCobrado <= 0) {
            return null;
        }
        $retenido = round(min(max($montoRetenido, 0.0), $montoCobrado), 2);
        return [
            'retenido' => $retenido,
            'estado'   => $montoCobrado > $retenido ? EstadoPago::A_REEMBOLSAB : EstadoPago::RETENIDO,
        ];
    }

    /** Estado del pago después de reembolsar: Retenido si el hotel se queda algo, si no Reembolsado. */
    public static function estadoTrasReembolso(float $montoRetenido): int
    {
        return $montoRetenido > 0 ? EstadoPago::RETENIDO : EstadoPago::REEMBOLSADO;
    }

    /** Cargo por late checkout: factor × precio de la noche, redondeado a centavos. */
    public static function lateFee(float $precioNoche, float $factor): float
    {
        return round($precioNoche * $factor, 2);
    }
}
