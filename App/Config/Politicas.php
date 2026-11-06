<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Reglas de negocio del hotel. Único lugar para modificarlas.
 *
 * Cambiar un valor afecta, desde el próximo deploy, a TODAS las reservas
 * que se evalúen en ese momento (no hay versionado por fecha todavía).
 * Ver REFACTOR-BD.md para el versionado en base de datos.
 */
final class Politicas
{
    /** Zona horaria usada por la app y los crons (date(), strtotime(), etc.). */
    public const ZONA_HORARIA = 'America/Argentina/Buenos_Aires';

    // ------------------------------------------------------------------
    // Pagos
    // ------------------------------------------------------------------

    /**
     * Horas antes del check-in en que se cancela una reserva sin pago total.
     * IMPORTANTE: el MySQL EVENT corte_pagos_48hs tiene este valor fijo;
     * si se modifica acá, actualizar también el EVENT.
     * Tras la migración 005 el EVENT pasa a llamarse corte_pagos y lee este valor
     * de la tabla Configuracion (clave horas_corte_pago): cambiarlo allí, no acá.
     */
    public const HORAS_CORTE_PAGO = 48;

    // ------------------------------------------------------------------
    // Horarios
    // ------------------------------------------------------------------

    /** Hora límite de checkout. También es la hora mínima para marcar No-Show. Formato HH:MM. */
    public const HORA_CHECKOUT = '11:00';

    // ------------------------------------------------------------------
    // Cargos
    // ------------------------------------------------------------------

    /** Cargo por late checkout, como fracción del precio base de la noche (0.5 = media noche). */
    public const FACTOR_LATE_CHECKOUT = 0.5;

    // ------------------------------------------------------------------
    // Tarifas
    // ------------------------------------------------------------------

    /**
     * Descuento por ocupación: [capacidad => [huespedes => descuento %]].
     * Las capacidades que no figuran (ej. Suite = 4) no tienen descuento.
     * La vista lo inyecta al JS con json_encode, no duplicar en JS.
     */
    public const DESCUENTO_OCUPACION = [
        3 => [1 => 25, 2 => 10, 3 => 0],
        2 => [1 => 15, 2 => 0],
    ];

    // ------------------------------------------------------------------
    // Seguridad
    // ------------------------------------------------------------------

    /** Minutos de validez del token de recuperación de contraseña. */
    public const MINUTOS_TOKEN_RECUPERACION = 60;

    /** Hora de checkout con segundos (HH:MM:SS), para comparar contra TIME/strings en SQL. */
    public static function horaCheckoutSql(): string
    {
        return self::HORA_CHECKOUT . ':00';
    }
}
