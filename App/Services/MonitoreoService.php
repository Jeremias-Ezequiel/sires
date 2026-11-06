<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EjecucionAutomatica;
use Throwable;

/**
 * Monitoreo de los procesos automáticos (EVENTs de la base y su respaldo por cron).
 * Cada corrida queda en Ejecuciones_Automaticas (migración 012); acá se decide si algo
 * está mal: programador apagado, EVENT ausente o desactivado, corrida con error o
 * proceso que no corre hace más de lo esperado.
 */
class MonitoreoService
{
    /** Ruta donde se ve el detalle (pestaña Procesos de la auditoría). */
    public const RUTA_DETALLE = '/dashboard/audit?vista=procesos';

    /**
     * Procesos vigilados. 'tolerancia' (minutos): sin una corrida correcta en ese lapso, se avisa.
     * Es la frecuencia más un margen (una corrida perdida no alcanza para alertar).
     */
    public const PROCESOS = [
        'corte_pagos' => [
            'nombre'     => 'Corte de pagos',
            'frecuencia' => 'Todos los días a las 08:00',
            'tolerancia' => 26 * 60,
        ],
        'cargo_late_checkout' => [
            'nombre'     => 'Cargo de late checkout',
            'frecuencia' => 'Cada 15 minutos',
            'tolerancia' => 45,
        ],
        'liberacion_no_show' => [
            'nombre'     => 'Liberación de No-Show',
            'frecuencia' => 'Cada 30 minutos',
            'tolerancia' => 90,
        ],
    ];

    /** Una corrida sin terminar más de esto se considera cortada. */
    private const MINUTOS_CORRIDA_COLGADA = 60;

    private EjecucionAutomatica $modelo;

    public function __construct()
    {
        $this->modelo = new EjecucionAutomatica();
    }

    /**
     * Estado completo para la pantalla de procesos.
     * @return array{scheduler: string, scheduler_ok: bool, procesos: array<string, array>}
     */
    public function estado(): array
    {
        $scheduler = strtoupper($this->modelo->estadoScheduler());
        $schedulerOk = $scheduler === 'ON';
        $eventos = $this->modelo->eventos();
        $ahora = time();

        $procesos = [];
        foreach (self::PROCESOS as $clave => $def) {
            $u = $this->modelo->ultimas($clave);
            $ultima = $u['ultima'];
            $ultimaOk = $u['ultima_ok'];
            $minutosDesdeOk = $ultimaOk !== null
                ? intdiv($ahora - strtotime((string)$ultimaOk['inicio']), 60)
                : null;
            $cronAlDia = $ultimaOk !== null && $ultimaOk['origen'] === 'cron'
                && $minutosDesdeOk <= $def['tolerancia'];

            $problema = null;
            if (!isset($eventos[$clave]) && !$cronAlDia) {
                $problema = 'El EVENT no existe en la base (correr los scripts 01 y 02).';
            } elseif (isset($eventos[$clave]) && $eventos[$clave]['status'] !== 'ENABLED' && !$cronAlDia) {
                $problema = 'El EVENT está desactivado.';
            } elseif ($ultima !== null && $ultima['ok'] !== null && (int)$ultima['ok'] === 0) {
                $problema = 'La última corrida falló: ' . mb_substr((string)$ultima['error'], 0, 160);
            } elseif ($ultima !== null && $ultima['ok'] === null
                && $ahora - strtotime((string)$ultima['inicio']) > self::MINUTOS_CORRIDA_COLGADA * 60) {
                $problema = 'Una corrida quedó sin terminar.';
            } elseif ($schedulerOk || $cronAlDia) {
                if ($ultimaOk === null) {
                    // Recién instalado: se espera la primera corrida antes de alertar
                    $creado = isset($eventos[$clave]) ? strtotime((string)$eventos[$clave]['created']) : $ahora;
                    if ($ahora - $creado > $def['tolerancia'] * 60) {
                        $problema = 'Nunca corrió.';
                    }
                } elseif ($minutosDesdeOk > $def['tolerancia']) {
                    $problema = 'No corre desde hace ' . self::duracion($minutosDesdeOk) . '.';
                }
            }

            $procesos[$clave] = $def + [
                'ultima'           => $ultima,
                'ultima_ok'        => $ultimaOk,
                'minutos_desde_ok' => $minutosDesdeOk,
                'problema'         => $problema,
            ];
        }

        return ['scheduler' => $scheduler, 'scheduler_ok' => $schedulerOk, 'procesos' => $procesos];
    }

    /**
     * Alertas para el inicio del administrador, con el mismo formato que DashboardService.
     * Si el programador está apagado se avisa una sola vez (todos los procesos dependen de él).
     */
    public function alertas(): array
    {
        $estado = $this->estado();
        $alertas = [];

        $procesosAlDia = array_filter($estado['procesos'], fn($p) => $p['problema'] === null);
        if (!$estado['scheduler_ok'] && count($procesosAlDia) < count(self::PROCESOS)) {
            $alertas[] = $this->alerta('El programador de tareas de la base está apagado (event_scheduler = '
                . $estado['scheduler'] . '): los procesos automáticos no corren.');
        }

        foreach ($estado['procesos'] as $p) {
            if ($p['problema'] !== null && ($estado['scheduler_ok'] || str_contains($p['problema'], 'falló'))) {
                $alertas[] = $this->alerta($p['nombre'] . ': ' . $p['problema']);
            }
        }
        return $alertas;
    }

    /** Historial de corridas para la pantalla de procesos. */
    public function historial(?string $proceso = null, int $limite = 50): array
    {
        if ($proceso !== null && !isset(self::PROCESOS[$proceso])) {
            $proceso = null;
        }
        return $this->modelo->listar($proceso, $limite);
    }

    /**
     * Ejecuta un proceso desde el cron de respaldo y deja la corrida registrada (origen 'cron').
     * $fn devuelve el array del Service; 'procesadas' (si viene) se guarda como filas afectadas.
     */
    public function ejecutarConRegistro(string $proceso, callable $fn): array
    {
        $id = $this->modelo->iniciar($proceso, 'cron');
        try {
            $resultado = $fn();
            $this->modelo->finalizar($id, true, isset($resultado['procesadas']) ? (int)$resultado['procesadas'] : null);
            return $resultado;
        } catch (Throwable $e) {
            $this->modelo->finalizar($id, false, null, mb_substr($e->getMessage(), 0, 1000));
            throw $e;
        }
    }

    public static function duracion(int $minutos): string
    {
        if ($minutos < 60) {
            return $minutos . ' min';
        }
        if ($minutos < 48 * 60) {
            return intdiv($minutos, 60) . ' h';
        }
        return intdiv($minutos, 1440) . ' días';
    }

    private function alerta(string $texto): array
    {
        return ['nivel' => 'danger', 'icono' => 'fa-gears', 'texto' => $texto, 'ruta' => self::RUTA_DETALLE];
    }
}
