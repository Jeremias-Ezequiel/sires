<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RecuperacionService;
use App\Services\TokenInvalidoException;
use Exception;

/**
 * Recuperación de contraseña sin sesión: pedido del enlace por mail y cambio con el token.
 * La lógica (límite de intentos, token, envío, validación) vive en RecuperacionService.
 */
class RecoveryController extends Controller
{
    /** Mensaje único para que no se pueda averiguar qué correos están registrados. */
    private const MENSAJE_ENVIADO = "¡Solicitud procesada! Si el correo pertenece a un usuario registrado, te enviamos las instrucciones para restablecer tu contraseña.";

    /** Formulario para ingresar el correo. */
    public function showRecoveryForm(): void
    {
        $errorMessage = $this->tomarDeSesion('error_message', null);

        require_once __DIR__ . '/../views/auth/forgot_password.phtml';
    }

    /** Genera el token y manda el enlace (si el correo existe). */
    public function processRecovery(): void
    {
        try {
            csrf_check();
            (new RecuperacionService())->solicitar((string)($_POST['email'] ?? ''));
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            $this->redirect('/password/recovery');
        }

        $_SESSION['flash_success'] = self::MENSAJE_ENVIADO;
        $this->redirect('/login');
    }

    /** Formulario para ingresar la nueva contraseña. */
    public function showResetForm(array $vars): void
    {
        $token = $vars['token'] ?? '';
        $errorMessage = $this->tomarDeSesion('error_message', null);

        require_once __DIR__ . '/../views/auth/reset_password.phtml';
    }

    /** Cambia la contraseña con el token del enlace. */
    public function processReset(): void
    {
        $token = (string)($_POST['token'] ?? '');

        try {
            csrf_check();
            (new RecuperacionService())->restablecer(
                $token,
                (string)($_POST['password'] ?? ''),
                (string)($_POST['confirm_password'] ?? '')
            );
        } catch (TokenInvalidoException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            $this->redirect('/password/recovery');
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            $this->redirect('/password/reset/' . rawurlencode($token));
        }

        $_SESSION['flash_success'] = "¡Contraseña actualizada con éxito! Ya podés iniciar sesión con tu nueva clave.";
        $this->redirect('/login');
    }
}
