<?php

namespace App\Controllers;

use Exception;
use App\Models\Usuario;
use App\Models\LoginIntento;
use App\Models\Auditoria;
use App\Middleware\AuthMiddleware;
use App\Helpers\UrlHelper;

class AuthController
{
    /**
     * Hash descartable para igualar el tiempo de respuesta cuando el mail no
     * existe en la base. Es un bcrypt real (cuesta lo mismo verificarlo que
     * uno de verdad) de una cadena aleatoria, asi que no corresponde a ninguna
     * clave. Si fuera un string cualquiera, password_verify cortaria al instante
     * y el hueco de tiempo volveria a delatar que mails estan registrados.
     */
    private const HASH_DUMMY = '$2y$10$aeq8ba6ffWO/JMbA1EH46unXcvXNtfnZhqsLhsF2buFKsRGwkSUzK';

    public function showLogin(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        require_once __DIR__ . '/../views/auth/login.phtml';
    }

    public function login(): void
    {
        // El CSRF se valida FUERA del try de negocio. Antes caia adentro, asi
        // que una peticion sin token valido contaba como intento fallido y
        // terminaba bloqueando a la persona; y el error de throttle tambien
        // pasaba por ahi, que sumaba un intento mas cada vez que se rechazaba
        // un intento. Ahora solo las fallas de credenciales cuentan.
        try {
            csrf_check();
        } catch (Exception $e) {
            $this->volverAlLogin($e->getMessage());
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $email    = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $intentos = new LoginIntento();

        try {
            $usuarioModel = new Usuario();

            // 1. Si falta un campo se avisa sin tocar contadores: es un error de
            // tipeo, no un intento de adivinar la clave.
            if (!is_string($email) || !is_string($password) || trim($email) === '' || $password === '') {
                throw new Exception("Por favor, complete todos los campos.");
            }

            // 2. El throttle por IP va antes de tocar la base de usuarios, para
            // que un barrido automatizado probando mails inexistente no genere
            // una consulta por cada intento.
            if ($intentos->contarFallosDesdeIp(client_ip()) >= LoginIntento::MAX_FALLOS_POR_IP) {
                throw new Exception(
                    "Demasiados intentos fallidos desde esta conexión. "
                    . "Intente nuevamente en " . LoginIntento::VENTANA_MINUTOS . " minutos."
                );
            }

            // 3. Delegamos la sanitizacion del mail al setter del modelo.
            $usuarioModel->setEmail($email);
            $user = $usuarioModel->findByEmail($usuarioModel->getEmail());

            // 4. Throttle por cuenta. El estado se relee de la base y no del
            // objeto que trae el SELECT, para no depender de una copia vieja.
            if ($user !== null) {
                $user->refrescarBloqueo();
                if ($user->estaBloqueado()) {
                    throw new Exception(
                        "Su cuenta está bloqueada por intentos fallidos. "
                        . "Intente nuevamente en " . $user->minutosRestantesBloqueo() . " minuto(s)."
                    );
                }
            }

            // 5. Credenciales. password_verify corre igual cuando el mail no
            // existe, con un hash falso, para que el tiempo de respuesta no
            // revele que mails estan registrados.
            $existe = $user !== null;
            $hash   = $existe ? $user->getPasswordHash() : self::HASH_DUMMY;
            $ok     = $existe && password_verify($password, $hash);

            if (!$ok) {
                // Solo se cuenta contra la cuenta si el mail existe: un mail
                // inventado no tiene intentos_fallidos que sumar.
                $user?->registrarFalloLogin();
                $intentos->registrar($user?->getId(), $usuarioModel->getEmail(), false);
                throw new Exception("Las credenciales ingresadas son incorrectas.");
            }

            if (!$user->getIsActive()) {
                // Cuenta desactivada: queda en el historial como intento
                // fallido para que no haya entradas sin rastro, pero sin sumar
                // al contador de la cuenta, que es un dato de seguridad.
                $intentos->registrar($user->getId(), $usuarioModel->getEmail(), false);
                throw new Exception("Su cuenta se encuentra desactivada. Contacte a un administrador.");
            }

            // 6. Todo ok: se limpia el bloqueo y se anota el acceso.
            $user->resetIntentosLogin();
            $user->registrarAcceso(client_ip());
            $intentos->registrar($user->getId(), $usuarioModel->getEmail(), true);

            session_regenerate_id(true);

            $_SESSION['user_name'] = $user->getNombre();
            $_SESSION['user_id']   = $user->getId();
            $_SESSION['user_role'] = $user->getIdRol();

            // Solo el login exitoso. Los fallidos ya quedan en Login_Intentos,
            // que para eso existe y guarda mas detalle del que da lugar esta
            // bitacora (guarda tambien los mails que no existen, que es lo que
            // sirve para ver un barrido). Duplicarlos aqui seria el mismo dato
            // en dos tablas que se purgan distinto.
            (new Auditoria())->registrar(
                Auditoria::LOGIN,
                'usuarios',
                (int)$user->getId(),
                "Inicio de sesion de " . $user->getEmail()
            );

            header('Location:' . UrlHelper::to('/dashboard'));
            exit;
        } catch (Exception $e) {
            $this->volverAlLogin($e->getMessage());
        }
    }

    /**
     * Redirige al login con el mensaje de error. Sale siempre: no hay return
     * despues porque exit corta el script.
     */
    private function volverAlLogin(string $mensaje): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['auth_error'] = $mensaje;
        header('Location:' . UrlHelper::to('/login'));
        exit;
    }
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // El token se valida acá y no después de destroySession(): al borrar la
        // sesión se va el token con ella, y el chequeo no encontraría con qué
        // compararse.
        try {
            csrf_check();
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location:' . UrlHelper::to('/login'));
            exit;
        }

        AuthMiddleware::destroySession();

        require_once __DIR__ . '/../views/auth/logout.phtml';
    }
}
