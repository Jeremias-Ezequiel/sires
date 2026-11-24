<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Cálculo de precios de una reserva, sin acceso a la base.
 *
 * La tabla de descuentos por ocupación ([capacidad => [huespedes => descuento %]]) se recibe
 * por parámetro: en la app sale de ConfiguracionService::descuentoOcupacion().
 * Misma lógica que public/js/reserva-precio.js.
 */
final class Tarifa
{
    /** Noches entre dos fechas Y-m-d (diferencia en días, sin signo). */
    public static function noches(string $fechaEntrada, string $fechaSalida): int
    {
        return (int)(new \DateTime($fechaEntrada))->diff(new \DateTime($fechaSalida))->days;
    }

    /**
     * Descuento en % según la capacidad de la habitación y los huéspedes.
     * Una capacidad o cantidad de huéspedes que no figura en la tabla no tiene descuento.
     *
     * @param array<int|string, array<int|string, int|float>> $tabla
     */
    public static function descuentoOcupacion(array $tabla, int $capacidad, int $huespedes): float
    {
        $porHuespedes = $tabla[$capacidad] ?? null;
        if (!is_array($porHuespedes)) {
            return 0.0;
        }
        return (float)($porHuespedes[$huespedes] ?? 0);
    }

    /**
     * Precio efectivo por noche: precio base con el descuento por ocupación aplicado.
     *
     * @param array<int|string, array<int|string, int|float>> $tabla
     */
    public static function precioEfectivoNoche(float $precioBase, int $capacidad, int $huespedes, array $tabla): float
    {
        return $precioBase * (1 - self::descuentoOcupacion($tabla, $capacidad, $huespedes) / 100);
    }

    /** Total de la estadía: precio por noche × noches. */
    public static function total(float $precioNoche, int $noches): float
    {
        return $precioNoche * $noches;
    }
}
