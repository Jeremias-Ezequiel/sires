<?php

declare(strict_types=1);

namespace App\Services;

use Exception;

/** El enlace de recuperación de contraseña venció, ya se usó o no existe. */
final class TokenInvalidoException extends Exception
{
    public function __construct()
    {
        parent::__construct("El enlace de recuperación ha expirado, ya fue utilizado o es inválido. Por favor, solicitá uno nuevo.");
    }
}
