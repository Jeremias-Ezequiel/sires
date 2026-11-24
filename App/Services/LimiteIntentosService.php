<?php

declare(strict_types=1);

namespace App\Services;

use Exception;

/**
 * Límite de intentos fallidos (fuerza bruta) para login y recuperación de contraseña.
 *
 * Cada contador se identifica por acción + IP + identificador (por ejemplo, el email)
 * y se guarda como archivo JSON en el directorio temporal del sistema. No requiere
 * tablas: si el directorio se borra, los contadores simplemente vuelven a cero.
 *
 * Uso:
 *   $limite = LimiteIntentosService::login();
 *   $limite->verificar($email);        // lanza Exception si está bloqueado
 *   ... si falla: $limite->registrarFallo($email);
 *   ... si sale bien: $limite->limpiar($email);
 */
final class LimiteIntentosService
{
    /** Login: intentos fallidos por email + IP antes de bloquear. */
    public const LOGIN_MAX_INTENTOS = 5;

    /** Login: intentos fallidos por IP (cualquier email) antes de bloquear. */
    public const LOGIN_MAX_INTENTOS_IP = 20;

    /** Recuperación: solicitudes de mail por IP antes de bloquear. */
    public const RECUPERACION_MAX_INTENTOS = 5;

    /** Ventana en la que se cuentan los intentos y duración del bloqueo (segundos). */
    public const VENTANA_SEGUNDOS = 900;

    private string $directorio;

    public function __construct(
        private string $accion,
        private int $maxIntentos,
        private int $ventanaSegundos = self::VENTANA_SEGUNDOS,
        ?string $directorio = null
    ) {
        $this->directorio = $directorio ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sires_intentos';
    }

    public static function login(): self
    {
        return new self('login', self::LOGIN_MAX_INTENTOS);
    }

    public static function loginPorIp(): self
    {
        return new self('login_ip', self::LOGIN_MAX_INTENTOS_IP);
    }

    public static function recuperacion(): self
    {
        return new self('recuperacion', self::RECUPERACION_MAX_INTENTOS);
    }

    /**
     * Lanza Exception si el identificador superó el máximo de intentos dentro de la ventana.
     */
    public function verificar(string $identificador = ''): void
    {
        $datos = $this->leer($identificador);
        if ($datos === null || $datos['intentos'] < $this->maxIntentos) {
            return;
        }

        $restante = $datos['desde'] + $this->ventanaSegundos - time();
        $minutos  = max(1, (int)ceil($restante / 60));

        throw new Exception("Demasiados intentos. Intente nuevamente en {$minutos} minuto(s).");
    }

    public function registrarFallo(string $identificador = ''): void
    {
        $this->modificar($identificador, function (?array $datos): array {
            if ($datos === null) {
                return ['intentos' => 1, 'desde' => time()];
            }
            $datos['intentos']++;
            return $datos;
        });
    }

    public function limpiar(string $identificador = ''): void
    {
        $archivo = $this->archivo($identificador);
        if (is_file($archivo)) {
            @unlink($archivo);
        }
    }

    // ------------------------------------------------------------------

    /** @return array{intentos:int, desde:int}|null Datos vigentes o null si no hay o venció la ventana. */
    private function leer(string $identificador): ?array
    {
        $archivo = $this->archivo($identificador);
        if (!is_file($archivo)) {
            return null;
        }

        $datos = json_decode((string)@file_get_contents($archivo), true);

        return $this->vigente($datos);
    }

    private function modificar(string $identificador, callable $cambio): void
    {
        if (!is_dir($this->directorio) && !@mkdir($this->directorio, 0700, true) && !is_dir($this->directorio)) {
            error_log('[SIRES LimiteIntentos] No se pudo crear ' . $this->directorio);
            return;
        }

        $fp = @fopen($this->archivo($identificador), 'c+');
        if ($fp === false) {
            error_log('[SIRES LimiteIntentos] No se pudo abrir el contador de ' . $this->accion);
            return;
        }

        try {
            flock($fp, LOCK_EX);
            $datos = $this->vigente(json_decode((string)stream_get_contents($fp), true));
            $nuevo = $cambio($datos);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)json_encode($nuevo));
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function vigente(mixed $datos): ?array
    {
        if (!is_array($datos) || !isset($datos['intentos'], $datos['desde'])) {
            return null;
        }
        if ((int)$datos['desde'] + $this->ventanaSegundos <= time()) {
            return null;
        }

        return ['intentos' => (int)$datos['intentos'], 'desde' => (int)$datos['desde']];
    }

    private function archivo(string $identificador): string
    {
        $ip    = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $clave = hash('sha256', $this->accion . '|' . $ip . '|' . mb_strtolower(trim($identificador)));

        return $this->directorio . DIRECTORY_SEPARATOR . $this->accion . '_' . $clave . '.json';
    }
}
