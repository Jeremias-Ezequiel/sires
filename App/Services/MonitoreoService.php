<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EjecucionAutomatica;
use App\Models\ColaMail;
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
        // Sin EVENT: un EVENT no puede mandar mails. Lo corre el crontab del servidor.
        'envio_mails' => [
            'nombre'     => 'Envío de mails al huésped',
            'frecuencia' => 'Cada 5 minutos (cron/mails.php)',
            'tolerancia' => 30,
            'solo_cron'  => true,
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
            $porScheduler = false;
            if (!empty($def['solo_cron'])) {
                $problema = $this->problemaSoloCron($clave, $def, $ultima, $ultimaOk, $minutosDesdeOk, $ahora);
            } elseif (!isset($eventos[$clave]) && !$cronAlDia) {
                $problema = 'El EVENT no existe en la base (correr los scripts 01 y 02).';
            } elseif (isset($eventos[$clave]) && $eventos[$clave]['status'] !== 'ENABLED' && !$cronAlDia) {
                $problema = 'El EVENT está desactivado.';
            } elseif ($ultima !== null && $ultima['ok'] !== null && (int)$ultima['ok'] === 0) {
                $problema = 'La última corrida falló: ' . mb_substr((string)$ultima['error'], 0, 160);
            } elseif ($ultima !== null && $ultima['ok'] === null
                && $ahora - strtotime((string)$ultima['inicio']) > self::MINUTOS_CORRIDA_COLGADA * 60) {
                $problema = 'Una corrida quedó sin terminar.';
            } elseif (!$schedulerOk && !$cronAlDia) {
                // Sin programador los EVENTs no corren, aunque la última corrida haya sido reciente
                $problema = 'No corre: el programador de tareas de la base está apagado.';
                $porScheduler = true;
            } elseif ($ultimaOk === null) {
                // Recién instalado: se espera la primera corrida antes de alertar
                $creado = isset($eventos[$clave]) ? strtotime((string)$eventos[$clave]['created']) : $ahora;
                if ($ahora - $creado > $def['tolerancia'] * 60) {
                    $problema = 'Nunca corrió.';
                }
            } elseif ($minutosDesdeOk > $def['tolerancia']) {
                $problema = 'No corre desde hace ' . self::duracion($minutosDesdeOk) . '.';
            }

            $procesos[$clave] = $def + [
                'ultima'           => $ultima,
                'ultima_ok'        => $ultimaOk,
                'minutos_desde_ok' => $minutosDesdeOk,
                'problema'         => $problema,
                'por_scheduler'    => $porScheduler,
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

        // Programador apagado: una sola alerta (todos los procesos afectados dependen de él)
        $afectados = array_filter($estado['procesos'], fn($p) => $p['por_scheduler']);
        if ($afectados !== []) {
            $alertas[] = $this->alerta('El programador de tareas de la base está apagado (event_scheduler = '
                . $estado['scheduler'] . '): no corren ' . implode(', ', array_column($afectados, 'nombre')) . '.');
        }

        foreach ($estado['procesos'] as $p) {
            if ($p['problema'] !== null && !$p['por_scheduler']) {
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

    /**
     * Problema de un proceso que corre solo por cron (sin EVENT ni event_scheduler).
     * Si nunca corrió, solo se avisa cuando hay trabajo esperando: puede que el servidor
     * todavía no tenga el crontab y eso no es un error mientras no haga falta.
     */
    private function problemaSoloCron(string $clave, array $def, ?array $ultima, ?array $ultimaOk, ?int $minutosDesdeOk, int $ahora): ?string
    {
        if ($ultima !== null && $ultima['ok'] !== null && (int)$ultima['ok'] === 0) {
            return 'La última corrida falló: ' . mb_substr((string)$ultima['error'], 0, 160);
        }
        if ($ultima !== null && $ultima['ok'] === null
            && $ahora - strtotime((string)$ultima['inicio']) > self::MINUTOS_CORRIDA_COLGADA * 60) {
            return 'Una corrida quedó sin terminar.';
        }
        $esperando = $clave === 'envio_mails' ? $this->mailsDemorados((int)$def['tolerancia']) : 0;
        if ($ultimaOk === null) {
            return $esperando > 0
                ? 'Nunca corrió y hay ' . $esperando . ' mail(s) esperando: programar cron/mails.php en el crontab del servidor.'
                : null;
        }
        if ($minutosDesdeOk > $def['tolerancia'] && $esperando > 0) {
            return 'No corre desde hace ' . self::duracion($minutosDesdeOk) . ' y hay ' . $esperando . ' mail(s) esperando.';
        }
        return null;
    }

    private function mailsDemorados(int $minutos): int
    {
        try {
            return (new ColaMail())->pendientesDemorados($minutos);
        } catch (\Throwable $e) {
            error_log('[SIRES MONITOREO] Cola_Mails: ' . $e->getMessage());
            return 0;
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
