<?php

declare(strict_types=1);

namespace App\Services;

use App\Dominio\ReglasEmpleado;
use App\Helpers\UrlHelper;
use App\Models\Usuario;
use App\Services\Mail\SmtpTransporte;
use App\Services\Mail\Transporte;
use Exception;
use Throwable;

/**
 * Recuperación de contraseña por mail: pedido del enlace (con límite de intentos) y cambio
 * de la contraseña con el token. El token se guarda hasheado (Usuario::saveResetToken).
 */
final class RecuperacionService
{
    private Usuario $usuarios;
    private Transporte $transporte;

    public function __construct(?Transporte $transporte = null)
    {
        $this->usuarios   = new Usuario();
        $this->transporte = $transporte ?? new SmtpTransporte();
    }

    /**
     * Genera el token y manda el enlace. Si el correo no está registrado no hace nada, sin
     * avisarlo (no se puede averiguar qué correos existen).
     * @throws Exception con el mensaje para el usuario (correo vacío, límite de intentos o fallo del envío).
     */
    public function solicitar(string $email): void
    {
        $email = trim($email);
        if ($email === '') {
            throw new Exception("Por favor, ingresá tu correo electrónico.");
        }

        $limite = LimiteIntentosService::recuperacion();
        $limite->verificar();
        // Cada solicitud cuenta (exista o no el correo) para evitar el envío masivo de mails
        $limite->registrarFallo();

        try {
            $usuario = $this->usuarios->findByEmail($email);
            if ($usuario === null) {
                return;
            }

            $minutos = ConfiguracionService::minutosTokenRecuperacion();
            $token = bin2hex(random_bytes(16));
            $usuario->saveResetToken($token, date('Y-m-d H:i:s', strtotime('+' . $minutos . ' minutes')));

            $html = (new MailService($this->transporte))->render('recuperacion', [
                'nombre'  => $usuario->getNombre(),
                'enlace'  => self::enlace($token),
                'minutos' => $minutos,
            ]);
            $this->transporte->enviar($usuario->getEmail(), 'Recuperación de Contraseña - SIRES', $html);
        } catch (Throwable $e) {
            // El detalle técnico (SMTP/BD) va al log, no al usuario
            error_log('[SIRES Recuperacion] ' . $e->getMessage());
            throw new Exception("No se pudo enviar el correo de recuperación. Intente nuevamente más tarde.");
        }
    }

    /**
     * Cambia la contraseña del usuario dueño del token y lo invalida.
     * @throws TokenInvalidoException si el token venció, ya se usó o no existe.
     * @throws Exception con el mensaje para el usuario si la contraseña no es válida.
     */
    public function restablecer(string $token, string $password, string $confirmacion): void
    {
        if ($password === '' || $confirmacion === '') {
            throw new Exception("Todos los campos de contraseña son obligatorios.");
        }
        if ($password !== $confirmacion) {
            throw new Exception("Las contraseñas ingresadas no coinciden.");
        }

        // Busca el token sin vencer de un usuario activo
        $usuario = $this->usuarios->findByValidToken($token);
        if ($usuario === null) {
            throw new TokenInvalidoException();
        }
        if (password_verify($password, $usuario->getPassword())) {
            throw new Exception("La nueva contraseña no puede ser igual a tu contraseña actual. Por favor, elegí una diferente.");
        }
        ReglasEmpleado::validarPassword($password, $confirmacion);

        $db = $this->usuarios->getConnection();
        $db->beginTransaction();
        try {
            $usuario->updatePasswordAfterReset($password);
            // Sin detalle: nunca se guarda la contraseña ni el token
            (new AuditoriaService())->registrar('empleado.recupero_password', 'empleado', $usuario->getId(),
                'Contraseña restablecida con el enlace de recuperación', null, []);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Enlace absoluto: la base sale de APP_URL (.env) para no confiar en el header Host del request. */
    private static function enlace(string $token): string
    {
        $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        if ($base === '') {
            $base = 'http://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        }
        return $base . UrlHelper::to('/password/reset/' . rawurlencode($token));
    }
}
