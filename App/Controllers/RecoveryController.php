<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Usuario;
use Exception;
use App\Services\ConfiguracionService;
use App\Services\LimiteIntentosService;
use App\Helpers\UrlHelper; // Importamos el helper para el ruteo dinámico

class RecoveryController
{
    /**
     * PANTALLA 3: Muestra el formulario para ingresar el correo
     */
    public function showRecoveryForm(): void
    {
        $errorMessage = $_SESSION['error_message'] ?? null;
        unset($_SESSION['error_message']);

        // Renderiza la vista de solicitud (Ruta nativa en disco)
        require_once __DIR__ . '/../views/auth/forgot_password.phtml';
    }

    /**
     * PROCESADOR PANTALLA 3: Verifica el email, genera el token y ENVÍA EL MAIL REAL CON PHPMailer
     */
    public function processRecovery(): void
    {
        try {
            csrf_check();
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ' . UrlHelper::to('/password/recovery'));
            exit;
        }

        $email = trim((string)($_POST['email'] ?? ''));

        // Mensaje único para que no se pueda averiguar qué correos están registrados
        $mensajeEnviado = "¡Solicitud procesada! Si el correo pertenece a un usuario registrado, te enviamos las instrucciones para restablecer tu contraseña.";

        if (empty($email)) {
            $_SESSION['error_message'] = "Por favor, ingresá tu correo electrónico.";
            header('Location: ' . UrlHelper::to('/password/recovery'));
            exit;
        }

        $limite = LimiteIntentosService::recuperacion();
        try {
            $limite->verificar();
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ' . UrlHelper::to('/password/recovery'));
            exit;
        }
        // Cada solicitud cuenta (exista o no el correo) para evitar el envío masivo de mails
        $limite->registrarFallo();

        $mail = null;
        try {
            $userModel = new Usuario();
            $usuario = $userModel->findByEmail($email);

            // Si el mail no existe respondemos igual que si existiera
            if (!$usuario) {
                $_SESSION['flash_success'] = $mensajeEnviado;
                header('Location: ' . UrlHelper::to('/login'));
                exit;
            }

            $minutosToken = ConfiguracionService::minutosTokenRecuperacion();

            // 🟢 1. Generamos un token aleatorio seguro de 32 caracteres y expiración según la configuración (minutos_token_recuperacion)
            $token = bin2hex(random_bytes(16));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $minutosToken . ' minutes'));

            // 🟢 2. Guardamos el token en la base de datos (fila del usuario)
            $usuario->saveResetToken($token, $expiresAt);

            // =================================================================
            // ✉️ INTEGRACIÓN REAL CON PHPMAILER (MAILTRAP)
            // =================================================================
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            // Configuración del servidor SMTP usando las variables cargadas de tu .env
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'] ?? 'sandbox.smtp.mailtrap.io';
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USER'] ?? '';
            $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 2525);
            $mail->CharSet    = 'UTF-8'; // Mantiene acentos y 'ñ' sin romperse

            // Remitente del sistema y destinatario dinámico mapeado del objeto Usuario
            $mail->setFrom('no-reply@sires.com', 'Sistema SIRES');
            $mail->addAddress($usuario->getEmail(), $usuario->getNombre());

            // Cuerpo en formato HTML
            $mail->isHTML(true);
            $mail->Subject = 'Recuperación de Contraseña - SIRES';

            // Enlace absoluto: la base sale de APP_URL (.env) para no confiar en el header Host del request
            $baseUrl  = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
            if ($baseUrl === '') {
                $baseUrl = 'http://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
            }
            $linkReal = htmlspecialchars($baseUrl . UrlHelper::to('/password/reset/' . rawurlencode($token)), ENT_QUOTES, 'UTF-8');

            // Maquetado del correo electrónico
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
                    <h2 style='color: #1e3a8a;'>Restablecer tu contraseña</h2>
                    <p>Hola, <strong>" . htmlspecialchars($usuario->getNombre()) . "</strong>.</p>
                    <p>Recibimos una solicitud para cambiar la clave de acceso a tu cuenta en el sistema SIRES.</p>
                    <p>Para proceder con el cambio, hacé clic en el siguiente botón:</p>
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$linkReal}' style='background-color: #1d4ed8; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>
                            Cambiar Contraseña
                        </a>
                    </div>
                    <p style='color: #6b7280; font-size: 13px;'>Este enlace es válido por {$minutosToken} minutos y vencerá de forma automática.</p>
                    <hr style='border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
                    <small style='color: #9ca3af;'>Si vos no solicitaste este cambio, podés ignorar este correo de forma segura.</small>
                </div>
            ";

            // Se despacha el correo
            $mail->send();

            // 🟢 3. Redirigimos al Login informando el éxito del envío
            $_SESSION['flash_success'] = $mensajeEnviado;
            header('Location: ' . UrlHelper::to('/login'));
            exit;
        } catch (Exception $e) {
            // El detalle técnico (SMTP/BD) va al log, no al usuario
            error_log('[SIRES Recuperacion] ' . $e->getMessage() . ($mail ? ' | ' . $mail->ErrorInfo : ''));
            $_SESSION['error_message'] = "No se pudo enviar el correo de recuperación. Intente nuevamente más tarde.";
            header('Location: ' . UrlHelper::to('/password/recovery'));
            exit;
        }
    }

    /**
     * PANTALLA 4: Muestra el formulario para ingresar la nueva contraseña
     */
    public function showResetForm(array $vars): void
    {
        $token = $vars['token'] ?? '';
        $errorMessage = $_SESSION['error_message'] ?? null;
        unset($_SESSION['error_message']);

        // Renderiza la vista de reseteo (Ruta nativa en disco)
        require_once __DIR__ . '/../views/auth/reset_password.phtml';
    }

    /**
     * PROCESADOR PANTALLA 4: Validación y cambio de clave con bloqueo de contraseña anterior
     */
    public function processReset(): void
    {
        $token           = (string)($_POST['token'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        try {
            csrf_check();
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ' . UrlHelper::to('/password/reset/' . rawurlencode($token)));
            exit;
        }

        // 1. Validaciones básicas de la interfaz de usuario
        if (empty($password) || empty($confirmPassword)) {
            $_SESSION['error_message'] = "Todos los campos de contraseña son obligatorios.";
            header('Location: ' . UrlHelper::to('/password/reset/' . rawurlencode($token)));
            exit;
        }

        if ($password !== $confirmPassword) {
            $_SESSION['error_message'] = "Las contraseñas ingresadas no coinciden.";
            header('Location: ' . UrlHelper::to('/password/reset/' . rawurlencode($token)));
            exit;
        }

        try {
            $userModel = new Usuario();

            // Busca el token, valida el tiempo y la baja lógica directamente
            $usuario = $userModel->findByValidToken($token);

            if (!$usuario) {
                $_SESSION['error_message'] = "El enlace de recuperación ha expirado, ya fue utilizado o es inválido. Por favor, solicitá uno nuevo.";
                header('Location: ' . UrlHelper::to('/password/recovery'));
                exit;
            }

            // 🛑 NUEVA VALIDACIÓN: Bloquear si la clave nueva es idéntica a la que ya tiene registrada
            if (password_verify($password, $usuario->getPassword())) {
                $_SESSION['error_message'] = "La nueva contraseña no puede ser igual a tu contraseña actual. Por favor, elegí una diferente.";
                header('Location: ' . UrlHelper::to('/password/reset/' . rawurlencode($token)));
                exit;
            }

            // Si pasa la validación, procesa el cambio (valida longitud >=5, hashea y limpia el token)
            $usuario->updatePasswordAfterReset($password);

            // Redirección exitosa al Login con confirmación visual
            $_SESSION['flash_success'] = "¡Contraseña actualizada con éxito! Ya podés iniciar sesión con tu nueva clave.";
            header('Location: ' . UrlHelper::to('/login'));
            exit;
        } catch (Exception $e) {
            // Atrapamos las excepciones controladas del modelo
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ' . UrlHelper::to('/password/reset/' . rawurlencode($token)));
            exit;
        }
    }
}
