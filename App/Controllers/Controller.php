<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\UrlHelper;
use Exception;

/**
 * Base común de los controllers.
 * La sesión ya está iniciada desde index.php; no hace falta session_start() en cada acción.
 */
abstract class Controller
{
    /** Redirige a una ruta interna (se resuelve con UrlHelper::to) y corta la ejecución. */
    protected function redirect(string $ruta): never
    {
        header('Location: ' . UrlHelper::to($ruta));
        exit;
    }

    /** Guarda un mensaje flash para mostrar en la próxima vista. $estado: 'success' | 'error'. */
    protected function flash(string $mensaje, string $estado = 'success'): void
    {
        $_SESSION['flash_message'] = $mensaje;
        $_SESSION['flash_status']  = $estado;
    }

    /** Guarda los datos enviados para volver a rellenar el formulario tras un error. */
    protected function recordarInputs(array $inputs): void
    {
        $_SESSION['old_inputs'] = $inputs;
    }

    /** Lee un valor de la sesión y lo borra (mensajes de una sola vez). */
    protected function tomarDeSesion(string $clave, mixed $porDefecto = ''): mixed
    {
        $valor = $_SESSION[$clave] ?? $porDefecto;
        unset($_SESSION[$clave]);
        return $valor;
    }

    /**
     * Mensaje flash pendiente, ya borrado de la sesión.
     * @return array{0: string, 1: string} [mensaje, estado]
     */
    protected function tomarFlash(): array
    {
        return [(string)$this->tomarDeSesion('flash_message'), (string)$this->tomarDeSesion('flash_status')];
    }

    /** Datos del formulario guardados por recordarInputs(), ya borrados de la sesión. */
    protected function tomarInputs(): array
    {
        $old = $this->tomarDeSesion('old_inputs', []);
        return is_array($old) ? $old : [];
    }

    /**
     * Id entero positivo de un campo del POST (o del array que se pase).
     * @throws Exception si falta o no es un entero positivo.
     */
    protected function idDe(string $campo = 'id', string $mensaje = 'ID inválido.', ?array $origen = null): int
    {
        $valor = filter_var(($origen ?? $_POST)[$campo] ?? '', FILTER_VALIDATE_INT);
        if ($valor === false || $valor <= 0) {
            throw new Exception($mensaje);
        }
        return $valor;
    }

    /**
     * Ejecuta una acción que cambia datos y vuelve con un mensaje flash.
     * La acción devuelve el resultado del Service (['message' => ...]); si lanza una
     * excepción, su mensaje se muestra como error y se vuelve a $rutaError (o a $rutaExito).
     * Con $recordarInputs se guarda el POST para volver a rellenar el formulario.
     */
    protected function ejecutarAccion(callable $accion, string $rutaExito, ?string $rutaError = null,
                                      bool $recordarInputs = false): never
    {
        try {
            $resultado = $accion();
            $this->flash((string)($resultado['message'] ?? 'Operación realizada.'));
        } catch (Exception $e) {
            if ($recordarInputs) {
                $this->recordarInputs($_POST);
            }
            $this->flash($e->getMessage(), 'error');
            $this->redirect($rutaError ?? $rutaExito);
        }
        $this->redirect($rutaExito);
    }
}
