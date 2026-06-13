<?php

declare(strict_types=1);

namespace App\Middleware;

class AuthMiddleware
{
    public static function verifyLogin(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 1. Caso: No existe la sesión del usuario
        if (!isset($_SESSION['user_id'])) {
            self::destroySession();

            // Iniciamos una sesión limpia flash solo para pasar el mensaje estético
            session_start();
            $_SESSION['auth_error'] = "Acceso denegado. Por favor, inicie sesión.";

            header('Location: ' . APP_PREFIX . '/login');
            exit;
        }

        // 2. Caso: Expiración por inactividad (30 min)
        $maxIdleTime = 1800;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $maxIdleTime)) {
            self::destroySession();

            session_start();
            $_SESSION['auth_error'] = "Su sesión ha expirado por inactividad.";

            header('Location: ' . APP_PREFIX . '/login');
            exit;
        }

        // Si pasó ambos filtros, actualizamos la marca de tiempo activa
        $_SESSION['last_activity'] = time();
    }

    public static function authorize(array $allowedRoles): void
    {
        // 2. Capturamos el rol del usuario de la sesión
        $userRole = (int)($_SESSION['user_role'] ?? 0);

        // 3. Verificación estricta: Si no estás en la lista, vas para afuera
        if (!in_array($userRole, $allowedRoles, true)) {
            $_SESSION['auth_error'] = "No tiene permisos suficientes para acceder a esta sección.";
            header('Location: ' . APP_PREFIX . '/dashboard');
            exit;
        }
    }

    /**
     * Verifica si el usuario actual tiene alguno de los roles suministrados.
     * Devuelve un booleano (true/false) ideal para usar en condicionales de las vistas.
     */
    public static function hasRole(array $allowedRoles): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userRole = (int)($_SESSION['user_role'] ?? 0);

        // Retorna true si el rol del usuario está en la lista de autorizados
        return in_array($userRole, $allowedRoles, true);
    }

    public static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
