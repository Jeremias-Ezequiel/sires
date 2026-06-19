<?php

namespace App\Controllers;

use Exception;
use App\Models\Usuario;
use App\Models\Rol;

class EmployeeController
{
    public function showEmployees(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $flashMessage = $_SESSION['flash_message'] ?? '';
        unset($_SESSION['flash_message']);

        $flashStatus = $_SESSION['flash_status'] ?? '';
        unset($_SESSION['flash_status']);

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $search = $_GET['search'] ?? "";
        $role      = $_GET['role_filter'] ?? "";
        $is_active = $_GET['status_filter'] ?? "";

        $hasSearch = !empty($_GET['search']);
        $hasRole = !empty($role);
        $hasStatus = isset($_GET['status_filter']) && $_GET['status_filter'] !== '';

        $rolModel = new Rol();
        $roles = $rolModel->getAll();

        $userModel = new Usuario();
        $listaEmpleados = $userModel->listEmployees($search, $is_active, $role);

        $contentView = __DIR__ . '/../views/dashboard/employees.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function showNewEmployeeForm(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $flashMessage = $_SESSION['flash_message'] ?? '';
        unset($_SESSION['flash_message']);

        $flashStatus = $_SESSION['flash_status'] ?? '';
        unset($_SESSION['flash_status']);

        $old = $_SESSION['old_inputs'] ?? [];
        unset($_SESSION['old_inputs']);

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $provinces = (new \App\Models\Provincia())->getAll();
        $cities    = (new \App\Models\Localidad())->getAll();
        $countries = (new \App\Models\Nacionalidad())->getAll();
        $roles     = (new \App\Models\Rol())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/addEmployee.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addEmployee(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            // 🔒 VALIDACIÓN DE CONTRASEÑAS EN EL CONTROLADOR
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($password)) {
                throw new Exception("La contraseña inicial es obligatoria.");
            }

            if ($password !== $confirmPassword) {
                throw new Exception("Las contraseñas ingresadas no coinciden.");
            }

            $usuario = new Usuario();

            $usuario->setNombre($_POST['nombre'] ?? '');
            $usuario->setApellido($_POST['apellido'] ?? '');
            $usuario->setEmail($_POST['email'] ?? '');
            $usuario->setPassword($password); // Ejecuta automáticamente password_hash() interno

            $usuario->setIdRol((int)($_POST['id_rol'] ?? 0));
            $usuario->setIdProvincia((int)($_POST['id_provincia'] ?? 0));
            $usuario->setIdLocalidad((int)($_POST['id_localidad'] ?? 0));
            $usuario->setIdNacionalidad((int)($_POST['id_nacionalidad'] ?? 0));

            $success = $usuario->save($usuario);

            if (!$success) {
                throw new Exception("No se pudo guardar el registro en el sistema. Por favor, revise la información ingresada o comuníquese con un administrador.");
            }

            $_SESSION['flash_message'] = "Empleado registrado exitosamente.";
            $_SESSION['flash_status']  = "success";
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";
        }

        header('Location: /sires/dashboard/employees/add');
        exit;
    }

/**
     * Procesa la baja lógica de un empleado (Inactivación)
     */
    public function deactivateEmployee(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $idToDeactivate = $_GET['id'] ?? '';
            
            if (empty($idToDeactivate)) {
                throw new Exception("Ocurrió un error al seleccionar el usuario. Intente nuevamente.");
            }

            if (filter_var($idToDeactivate, FILTER_VALIDATE_INT) === false) {
                throw new Exception("El ID tiene que ser estrictamente un número entero.");
            }
            $userModel = new Usuario();            
            // Si ya estaba inactivo o no existe, el modelo devuelve false usando rowCount()
            if (!$userModel->deactivate((int)$idToDeactivate)) {
                throw new Exception("El empleado no existe o ya se encuentra inactivo en el sistema.");
            }

            $_SESSION['flash_message'] = "Empleado desactivado exitosamente.";
            $_SESSION['flash_status']  = "success";
            
            header('Location: /sires/dashboard/employees');
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: /sires/dashboard/employees');
            exit;
        }
    }

    /**
     * Procesa la reactivación del acceso de un empleado (Activación)
     */
    public function activateEmployee(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $idToActivate = $_GET['id'] ?? '';
            
            if (empty($idToActivate)) {
                throw new Exception("Ocurrió un error al seleccionar el usuario. Intente nuevamente.");
            }

            if (filter_var($idToActivate, FILTER_VALIDATE_INT) === false) {
                throw new Exception("El ID tiene que ser estrictamente un número entero.");
            }
            
            $userModel = new Usuario();            
            // Si ya estaba activo o no existe, el modelo devuelve false usando rowCount()
            if (!$userModel->activate((int)$idToActivate)) {
                throw new Exception("El empleado no existe o ya se encuentra activo en el sistema.");
            }

            $_SESSION['flash_message'] = "Empleado reactivado exitosamente.";
            $_SESSION['flash_status']  = "success";
            
            header('Location: /sires/dashboard/employees');
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: /sires/dashboard/employees');
            exit;
        }
    }

}

