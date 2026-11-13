<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Dominio\ReglasEjecucion;
use App\Models\EjecucionAutomatica;
use Throwable;

/**
 * Ejecución por pedido de los procesos automáticos: para servidores sin event_scheduler ni
 * crontab. Al terminar un pedido de un usuario logueado (o el latido del dashboard), corre los
 * procesos vencidos llamando a los mismos Services que el cron de respaldo.
 *
 * Convive con las otras vías: si un EVENT o el cron ya corrió, el proceso no está vencido y acá
 * no se hace nada. Con el scheduler de la base a cargo, solo actúa si el proceso superó su
 * tolerancia (red de seguridad). Cada proceso toma un GET_LOCK para que dos pedidos simultáneos
 * no lo corran a la vez. Se apaga con EJECUCION_POR_PEDIDO=0 en .env.
 */
class EjecucionPorPedidoService
{
    /** Mails por corrida: pocos, para que una caída del SMTP no demore la página. */
    public const LOTE_MAILS = 3;

    private static bool $programado = false;

    private EjecucionAutomatica $modelo;
    private ?bool $schedulerOn = null;
    /** @var array<string, string>|null nombre del EVENT => status */
    private ?array $eventos = null;

    public function __construct()
    {
        $this->modelo = new EjecucionAutomatica();
    }

    public static function habilitado(): bool
    {
        $valor = strtolower(trim((string)($_ENV['EJECUCION_POR_PEDIDO'] ?? '1')));
        return !in_array($valor, ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Deja programada la corrida para el final del pedido (también corre si el controller
     * termina con redirect/exit). Solo para usuarios logueados y respuestas sin error.
     */
    public static function programarAlTerminar(): void
    {
        if (self::$programado || PHP_SAPI === 'cli' || !self::habilitado()) {
            return;
        }
        self::$programado = true;

        register_shutdown_function(static function (): void {
            if (!isset($_SESSION['user_id']) || http_response_code() >= 500) {
                return;
            }
            // Liberar la sesión (otros pedidos del mismo usuario no esperan) y, con PHP-FPM,
            // entregar la respuesta antes de correr los procesos.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);

            try {
                (new self())->ejecutarVencidos();
            } catch (Throwable $e) {
                error_log('[SIRES POR PEDIDO ERROR] ' . $e->getMessage());
            }
        });
    }

    /**
     * Corre los procesos vencidos. Un error en uno se loguea (y queda en el monitoreo) sin
     * cortar los demás.
     * @return list<string> procesos que se ejecutaron
     */
    public function ejecutarVencidos(?int $ahora = null): array
    {
        $ejecutados = [];
        foreach (array_keys(ReglasEjecucion::PROGRAMACION) as $proceso) {
            try {
                if ($this->leTocaCorrer($proceso, $ahora ?? time()) && $this->ejecutarConLock($proceso, $ahora)) {
                    $ejecutados[] = $proceso;
                }
            } catch (Throwable $e) {
                error_log('[SIRES POR PEDIDO ERROR] ' . $proceso . ': ' . $e->getMessage());
            }
        }
        return $ejecutados;
    }

    private function leTocaCorrer(string $proceso, int $ahora): bool
    {
        $u = $this->modelo->ultimas($proceso);
        $ultima = $u['ultima'];
        $ultimaOk = $u['ultima_ok'];
        $ultimaInicio = $ultima !== null ? (int)strtotime((string)$ultima['inicio']) : null;
        $ultimaTermino = $ultima !== null ? $ultima['ok'] !== null : null;
        $ultimaOkInicio = $ultimaOk !== null ? (int)strtotime((string)$ultimaOk['inicio']) : null;

        if (!ReglasEjecucion::vencido(ReglasEjecucion::PROGRAMACION[$proceso], $ultimaInicio, $ultimaTermino,
                                      $ultimaOkInicio, $ahora)) {
            return false;
        }
        // Vencido: si su EVENT está activo y el scheduler prendido, solo como red de seguridad
        if ($this->aCargoDelScheduler($proceso)) {
            return ReglasEjecucion::superaTolerancia($ultimaInicio, $ultimaTermino, $ultimaOkInicio,
                (int)MonitoreoService::PROCESOS[$proceso]['tolerancia'], $ahora);
        }
        return true;
    }

    private function ejecutarConLock(string $proceso, ?int $ahora): bool
    {
        $db = (new Database())->getConnection();
        $nombreLock = 'sires_auto_' . $proceso;
        $lock = $db->prepare('SELECT GET_LOCK(:n, 0)');
        $lock->execute([':n' => $nombreLock]);
        if ((int)$lock->fetchColumn() !== 1) {
            return false; // otro pedido lo está corriendo
        }

        try {
            // Releer con el lock tomado: otro pedido pudo terminarlo recién
            if (!$this->leTocaCorrer($proceso, $ahora ?? time())) {
                return false;
            }
            (new MonitoreoService())->ejecutarConRegistro($proceso, $this->tarea($proceso), 'pedido');
            return true;
        } finally {
            $libera = $db->prepare('SELECT RELEASE_LOCK(:n)');
            $libera->execute([':n' => $nombreLock]);
        }
    }

    /** El mismo Service que usan los cron de respaldo. */
    private function tarea(string $proceso): callable
    {
        return match ($proceso) {
            'corte_pagos'         => fn() => (new PagoService())->ejecutarCortePagos(),
            'cargo_late_checkout' => fn() => (new PagoService())->aplicarLateCheckout(),
            'liberacion_no_show'  => fn() => (new ReservaService())->liberarNoShowConPago(),
            'envio_mails'         => fn() => (new MailService())->procesarCola(self::LOTE_MAILS),
            default               => throw new \InvalidArgumentException('Proceso desconocido: ' . $proceso),
        };
    }

    private function aCargoDelScheduler(string $proceso): bool
    {
        if (!empty(MonitoreoService::PROCESOS[$proceso]['solo_cron'])) {
            return false;
        }
        if ($this->schedulerOn === null) {
            $this->schedulerOn = strtoupper($this->modelo->estadoScheduler()) === 'ON';
        }
        if (!$this->schedulerOn) {
            return false;
        }
        if ($this->eventos === null) {
            $this->eventos = array_map(fn(array $e): string => (string)$e['status'], $this->modelo->eventos());
        }
        return ($this->eventos[$proceso] ?? '') === 'ENABLED';
    }
}
