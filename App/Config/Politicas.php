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

    /**
     * Seña mínima para confirmar una reserva, en % del total.
     * Clave en Configuracion: sena_minima_pct.
     */
    public const SENA_MINIMA_PCT = 30;

    // ------------------------------------------------------------------
    // Reservas
    // ------------------------------------------------------------------

    /** Máximo de noches de una reserva (alta y edición). Clave: max_noches_reserva. */
    public const MAX_NOCHES_RESERVA = 30;

    /**
     * Cancelación manual sin cargo si faltan al menos estas horas para el día de entrada
     * (contadas hasta las 00:00 de fecha_entrada). Clave: cancelacion_gratis_horas.
     */
    public const CANCELACION_GRATIS_HORAS = 72;

    /**
     * Noches retenidas (sobre el precio pactado, con tope en lo cobrado) al cancelar
     * con menos anticipación. El resto de lo cobrado queda A Reembolsar.
     * Clave: cancelacion_penalidad_noches.
     */
    public const CANCELACION_PENALIDAD_NOCHES = 1;

    /**
     * No-Show con pago: noches que se retiene la habitación y que se cobran como penalidad
     * (con tope en lo cobrado). Se libera el día entrada + N a la hora de checkout.
     * Clave: noshow_noches_retenidas (también la lee el EVENT liberacion_no_show).
     */
    public const NOSHOW_NOCHES_RETENIDAS = 1;

    // ------------------------------------------------------------------
    // Horarios
    // ------------------------------------------------------------------

    /** Hora límite de checkout. Formato HH:MM. */
    public const HORA_CHECKOUT = '11:00';

    /** Hora del día de entrada desde la que se puede marcar No-Show. Clave: hora_no_show. */
    public const HORA_NO_SHOW = '23:00';

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
