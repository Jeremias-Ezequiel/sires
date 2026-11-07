<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Reglas de cancelación y No-Show sin acceso a la base.
 * Las fechas y la hora actual se reciben por parámetro para poder probarlas.
 */
final class ReglasReserva
{
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
