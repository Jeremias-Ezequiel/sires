<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Reglas de negocio del hotel: valores POR DEFECTO.
 *
 * La fuente real es la tabla Configuracion (versionada por fecha), leída con
 * App\Services\ConfiguracionService. Estas constantes solo se usan si la tabla
 * o la clave no existen. Para cambiar una política, insertar una fila nueva en
 * Configuracion con su vigente_desde; no modificar estos valores.
 *
 * Excepción: ZONA_HORARIA se usa directamente (index.php y cron/bootstrap.php).
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
     * Clave en Configuracion: horas_corte_pago (también la lee el EVENT corte_pagos).
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
}
