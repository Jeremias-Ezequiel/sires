<?php

namespace App\Controllers;

use Exception;
use App\Models\Usuario;
use App\Middleware\AuthMiddleware;

class AuthController
{
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
        try {
            // 1. Capturamos los datos crudos del formulario directamente
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';

            // 2. Validación de presencia básica (Controlador se encarga del flujo)
            if (empty($email) || empty($password)) {
                throw new Exception("Por favor, complete todos los campos.");
            }

            // 3. Instanciamos el modelo vacío para usar sus capacidades
            $usuarioModel = new Usuario();

            // 4. Delegamos la sanitización y validación del formato al setear el email.
            // Si el formato es inválido, el setter lanzará una Exception que caerá en el catch de abajo.
            $usuarioModel->setEmail($email);

            // 5. Buscamos al usuario usando el email que ya fue limpiado por el modelo
            $user = $usuarioModel->findByEmail($usuarioModel->getEmail());

            // 6. Verificación de credenciales usando el método de negocio de tu modelo
            // verifyPassword internamente maneja de forma segura password_verify()
            if (!$user || !$user->verifyPassword($password)) {
                throw new Exception("Las credenciales ingresadas son incorrectas.");
            }

            // 7. Iniciar sesión exitosa si todo salió bien
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $_SESSION['user_id'] = $user->getId();
            $_SESSION['user_role'] = $user->getIdRol();

            header('Location: /sires/dashboard');
            exit;
        } catch (Exception $e) {
            // Cualquier excepción (campos vacíos, formato de mail roto o clave inválida)
            // se captura acá de forma centralizada y se envía a la vista.
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $_SESSION['auth_error'] = $e->getMessage();
            header('Location: /sires/login');
            exit;
        }
    }
    public function logout(): void
    {
        AuthMiddleware::destroySession();

        require_once __DIR__ . '/../views/auth/logout.phtml';
    }
}
