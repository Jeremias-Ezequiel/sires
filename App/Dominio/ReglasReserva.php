<?php

declare(strict_types=1);

namespace App\Dominio;

use App\Models\EstadoHabitacion;
use App\Models\EstadoReserva;

/**
 * Reglas de reservas sin acceso a la base: fechas, habitaciones reservables, cancelación y No-Show.
 * Las fechas y la hora actual se reciben por parámetro para poder probarlas.
 */
final class ReglasReserva
{
    /** Salida en la fecha pactada (o después). */
    public const CHECKOUT = 'checkout';
    /** Salida antes de la fecha pactada: las noches no usadas no se reembolsan. */
    public const CHECKOUT_ANTICIPADO = 'anticipado';

    /**
     * Estados de habitación en los que no se puede asignar una reserva.
     * Ocupada, Sucia y Limpiando son estados del día: no impiden reservar a futuro
     * (el check-in sigue exigiendo que la habitación esté Disponible).
     */
    public const ESTADOS_HABITACION_NO_RESERVABLES = [
        EstadoHabitacion::MANTENIMIENTO,
        EstadoHabitacion::BLOQUEADA,
    ];

    public static function habitacionReservable(int $idEstadoHabitacion): bool
    {
        return !in_array($idEstadoHabitacion, self::ESTADOS_HABITACION_NO_RESERVABLES, true);
    }

    /** Verifica que la fecha tenga formato Y-m-d y exista en el calendario (ej. rechaza 2026-02-30). */
    public static function esFechaValida(string $fecha): bool
    {
        $dt = \DateTime::createFromFormat('!Y-m-d', $fecha);
        return $dt !== false && $dt->format('Y-m-d') === $fecha;
    }

    /**
     * Qué salida corresponde a una reserva: solo con el huésped en casa; check-out desde el
     * día de salida y checkout anticipado antes. null si todavía no puede salir (no está en casa).
     */
    public static function tipoCheckout(int $idEstadoReserva, string $fechaSalida, string $hoy): ?string
    {
        if ($idEstadoReserva !== EstadoReserva::EN_CASA) {
            return null;
        }
        return $fechaSalida > $hoy ? self::CHECKOUT_ANTICIPADO : self::CHECKOUT;
    }

    /** Horas que faltan desde $ahora (timestamp) hasta las 00:00 del día de entrada. Negativo si ya pasó. */
    public static function horasAnticipacion(string $fechaEntrada, int $ahora): float
    {
        return ((int)strtotime($fechaEntrada . ' 00:00:00') - $ahora) / 3600;
    }

    /**
     * Monto que retiene el hotel al cancelar: nada con anticipación suficiente; si no,
     * N noches (sin pasar las de la reserva) al precio pactado. El tope en lo cobrado
     * lo aplica ReglasPago::retencion().
     */
    public static function retencionCancelacion(
        float $precioNoche,
        int $nochesReserva,
        float $horasAnticipacion,
        PoliticaReserva $politica
    ): float {
        if ($horasAnticipacion >= $politica->cancelacionGratisHoras) {
            return 0.0;
        }
        return $precioNoche * min($nochesReserva, $politica->cancelacionPenalidadNoches);
    }

    /** Día en que se libera un No-Show con pago: entrada + noches retenidas, sin pasar la salida. */
    public static function fechaLiberacionNoShow(string $fechaEntrada, string $fechaSalida, int $nochesRetenidas): string
    {
        $liberacion = date('Y-m-d', (int)strtotime($fechaEntrada . ' +' . $nochesRetenidas . ' day'));
        return min($liberacion, $fechaSalida);
    }

    /** Monto que retiene el hotel al liberar un No-Show con pago (tope en lo cobrado: ReglasPago::retencion()). */
    public static function retencionNoShow(float $precioNoche, int $nochesRetenidas): float
    {
        return $precioNoche * $nochesRetenidas;
    }

    /**
     * Valida si ya se puede marcar No-Show. Devuelve el mensaje de error, o null si se puede.
     * $hoy en Y-m-d y $horaActual / $horaNoShow en HH:MM.
     */
    public static function errorNoShow(string $fechaEntrada, string $hoy, string $horaActual, string $horaNoShow): ?string
    {
        if ($fechaEntrada > $hoy) {
            return 'No se puede marcar como No-Show antes de la fecha de entrada.';
        }
        if ($fechaEntrada === $hoy && $horaActual < $horaNoShow) {
            return 'El No-Show solo puede marcarse después de las ' . $horaNoShow . ' del día de entrada.';
        }
        return null;
    }

    /** Agrega un mensaje al final de las observaciones, en su propia línea: "- Mensaje". */
    public static function agregarObservacion(?string $actual, string $mensaje): string
    {
        $actual = rtrim((string)$actual);
        return ($actual === '' ? '' : $actual . "\n") . '- ' . $mensaje;
    }

    /**
     * Observaciones listas para mostrar o editar (texto plano: la vista escapa).
     * Normaliza los datos anteriores a 1.8.0: mensajes separados con " | " y texto
     * guardado ya escapado como HTML.
     */
    public static function observacionesLegibles(?string $observaciones): string
    {
        $texto = html_entity_decode((string)$observaciones, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(str_replace(' | ', "\n- ", $texto));
    }
}
