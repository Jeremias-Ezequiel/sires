<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Cuándo le toca correr a un proceso automático (corte de pagos, late checkout, No-Show, mails).
 *
 * Los procesos tienen tres vías que conviven: los EVENTs de la base (si event_scheduler está
 * activo), el crontab (si hay acceso) y la ejecución por pedido (la app los corre al navegar el
 * sistema). Esta regla decide, a partir de las corridas registradas en Ejecuciones_Automaticas,
 * si un proceso está vencido. Sin PDO ni reloj implícito: todo entra por parámetro (timestamps).
 */
final class ReglasEjecucion
{
    /**
     * Programación de cada proceso: 'cada' => minutos entre corridas, o 'diario' => 'HH:MM'.
     * Tienen que coincidir con los EVENTs (scripts 01 y 02) y con el crontab sugerido.
     */
    public const PROGRAMACION = [
        'corte_pagos'         => ['diario' => '08:00'],
        'cargo_late_checkout' => ['cada' => 15],
        'liberacion_no_show'  => ['cada' => 30],
        'envio_mails'         => ['cada' => 5],
    ];

    /** Una corrida sin terminar más de esto se considera cortada (no bloquea la siguiente). */
    public const MINUTOS_CORRIDA_COLGADA = 60;

    /** Proceso diario: espera antes de reintentar una corrida que falló. */
    public const MINUTOS_REINTENTO_DIARIO = 15;

    /**
     * ¿Le toca correr al proceso?
     *
     * @param array{cada?: int, diario?: string} $programacion una entrada de PROGRAMACION
     * @param int|null  $ultimaInicio    inicio de la última corrida (cualquier resultado)
     * @param bool|null $ultimaTermino   false si la última corrida sigue en curso (ok = NULL)
     * @param int|null  $ultimaOkInicio  inicio de la última corrida que terminó bien
     */
    public static function vencido(array $programacion, ?int $ultimaInicio, ?bool $ultimaTermino,
                                   ?int $ultimaOkInicio, int $ahora): bool
    {
        if (self::enCurso($ultimaInicio, $ultimaTermino, $ahora)) {
            return false;
        }

        if (isset($programacion['diario'])) {
            $hoyALaHora = strtotime(date('Y-m-d', $ahora) . ' ' . $programacion['diario']);
            if ($hoyALaHora === false || $ahora < $hoyALaHora) {
                return false;
            }
            if ($ultimaOkInicio !== null && $ultimaOkInicio >= $hoyALaHora) {
                return false;
            }
            // Hoy no corrió bien: si recién falló, se espera antes de reintentar
            return $ultimaInicio === null || $ultimaInicio < $hoyALaHora
                || $ahora - $ultimaInicio >= self::MINUTOS_REINTENTO_DIARIO * 60;
        }

        $cada = (int)($programacion['cada'] ?? 0);
        if ($cada <= 0) {
            return false;
        }
        // Se mide desde la última corrida de cualquier resultado: una que falla no se
        // reintenta en cada pedido, sino con la misma frecuencia del proceso.
        return $ultimaInicio === null || $ahora - $ultimaInicio >= $cada * 60;
    }

    /**
     * Con el scheduler de la base a cargo, la ejecución por pedido es solo una red de seguridad:
     * actúa cuando el proceso no corrió bien dentro de su tolerancia (la misma del monitoreo).
     */
    public static function superaTolerancia(?int $ultimaInicio, ?bool $ultimaTermino, ?int $ultimaOkInicio,
                                            int $toleranciaMinutos, int $ahora): bool
    {
        if (self::enCurso($ultimaInicio, $ultimaTermino, $ahora)) {
            return false;
        }
        return $ultimaOkInicio === null || $ahora - $ultimaOkInicio > $toleranciaMinutos * 60;
    }

    /** Corrida empezada y sin terminar que todavía no se considera cortada. */
    public static function enCurso(?int $ultimaInicio, ?bool $ultimaTermino, int $ahora): bool
    {
        return $ultimaInicio !== null && $ultimaTermino === false
            && $ahora - $ultimaInicio < self::MINUTOS_CORRIDA_COLGADA * 60;
    }
}
