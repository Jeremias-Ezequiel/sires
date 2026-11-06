<?php

declare(strict_types=1);

namespace App\Dominio;

use App\Models\EstadoReserva;

/**
 * Cálculos del planning de ocupación (Gantt), sin acceso a la base:
 * rango de días, posición de cada barra y cómo se muestra cada estado.
 *
 * Una barra ocupa las noches de la reserva: entrada 10, salida 12 → columnas 10 y 11.
 * El día de salida queda libre para otra entrada, igual que el control de solapamiento.
 */
final class Planning
{
    /** Cantidad de días que se pueden ver. El primero es el valor por defecto. */
    public const DIAS_PERMITIDOS = [14, 30];

    /** Estados que se dibujan (ocupan o ocuparon la habitación). Cancelada y No-Show sin pago no. */
    public const ESTADOS_VISIBLES = [
        EstadoReserva::PENDIENTE,
        EstadoReserva::CONFIRMADA,
        EstadoReserva::EN_CASA,
        EstadoReserva::FINALIZADA_CHECKOUT,
        EstadoReserva::NO_SHOW_CON_PAGO,
    ];

    /** Clase visual => [texto de la leyenda, ícono Font Awesome]. */
    public const ESTILOS = [
        'pendiente'  => ['Pendiente', 'fa-clock'],
        'confirmada' => ['Confirmada', 'fa-calendar-check'],
        'en-casa'    => ['En casa', 'fa-user'],
        'sale-hoy'   => ['Sale hoy', 'fa-door-open'],
        'no-show'    => ['No-Show (habitación retenida)', 'fa-user-clock'],
        'finalizada' => ['Finalizada', 'fa-flag-checkered'],
    ];

    private const DIAS_SEMANA = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    public static function normalizarDias(mixed $dias): int
    {
        $n = filter_var($dias, FILTER_VALIDATE_INT);
        return in_array($n, self::DIAS_PERMITIDOS, true) ? $n : self::DIAS_PERMITIDOS[0];
    }

    /** Fecha de inicio válida (Y-m-d existente) o $hoy. */
    public static function normalizarDesde(mixed $desde, string $hoy): string
    {
        if (!is_string($desde)) {
            return $hoy;
        }
        $dt = \DateTime::createFromFormat('!Y-m-d', $desde);
        return $dt !== false && $dt->format('Y-m-d') === $desde ? $desde : $hoy;
    }

    /** $fecha + $dias (negativo para retroceder). */
    public static function desplazar(string $fecha, int $dias): string
    {
        return (new \DateTimeImmutable($fecha))->modify(($dias >= 0 ? '+' : '') . $dias . ' day')->format('Y-m-d');
    }

    /**
     * Columnas del rango.
     *
     * @return list<array{fecha: string, numero: int, semana: string, hoy: bool, finde: bool, pasado: bool, primeroDeMes: bool}>
     */
    public static function dias(string $desde, int $dias, string $hoy): array
    {
        $out = [];
        $dia = new \DateTimeImmutable($desde);
        for ($i = 0; $i < $dias; $i++) {
            $fecha = $dia->format('Y-m-d');
            $semana = (int)$dia->format('w');
            $out[] = [
                'fecha'        => $fecha,
                'numero'       => (int)$dia->format('j'),
                'semana'       => self::DIAS_SEMANA[$semana],
                'hoy'          => $fecha === $hoy,
                'finde'        => $semana === 0 || $semana === 6,
                'pasado'       => $fecha < $hoy,
                'primeroDeMes' => $dia->format('j') === '1',
            ];
            $dia = $dia->modify('+1 day');
        }
        return $out;
    }

    /**
     * Posición de una reserva en el rango: columna inicial (0 = $desde), cantidad de columnas
     * y si la estadía sigue antes o después de lo visible. null si no cae en el rango.
     *
     * @return array{inicio: int, span: int, sigueAntes: bool, sigueDespues: bool}|null
     */
    public static function barra(string $entrada, string $salida, string $desde, int $dias): ?array
    {
        $hasta = self::desplazar($desde, $dias);
        if ($salida <= $entrada || $entrada >= $hasta || $salida <= $desde) {
            return null;
        }
        $ini = max($entrada, $desde);
        $fin = min($salida, $hasta);
        return [
            'inicio'       => Tarifa::noches($desde, $ini),
            'span'         => Tarifa::noches($ini, $fin),
            'sigueAntes'   => $entrada < $desde,
            'sigueDespues' => $salida > $hasta,
        ];
    }

    /** Clase visual de una reserva (clave de ESTILOS). */
    public static function estilo(int $idEstado, string $salida, string $hoy): string
    {
        return match ($idEstado) {
            EstadoReserva::PENDIENTE        => 'pendiente',
            EstadoReserva::CONFIRMADA       => 'confirmada',
            EstadoReserva::EN_CASA          => $salida <= $hoy ? 'sale-hoy' : 'en-casa',
            EstadoReserva::NO_SHOW_CON_PAGO => 'no-show',
            default                         => 'finalizada',
        };
    }
}
