<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\UrlHelper;

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
}
