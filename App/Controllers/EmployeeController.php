<?php

namespace App\Controllers;

use Exception;
use App\Models\Usuario;
use App\Models\Rol;
use App\Helpers\UrlHelper; // Importamos el helper para las redirecciones dinámicas

class EmployeeController
{
    public function showEmployees(array $vars): void
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

        // --- LÓGICA DE PAGINACIÓN --//
        // --- LÓGICA DE PAGINACIÓN ---
        $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($currentPage < 1) { $currentPage = 1; }
        
        $limit = 5; // Cambiá este número para definir cuántos empleados ver por página
        $offset = ($currentPage - 1) * $limit;

        $search = $vars['search'] ?? "";
        $role      = $vars['role_filter'] ?? "";
        $is_active = $vars['status_filter'] ?? "";

        $hasSearch = !empty($vars['search']);
        $hasRole = !empty($role);
        $hasStatus = isset($vars['status_filter']) && $vars['status_filter'] !== '';

        $roles = (new Rol())->getAll();

        $userModel = new Usuario();
        
        // 1. Contamos el total bajo esos filtros
        $totalEmployees = $userModel->countEmployees($search, $is_active, $role);
        $totalPages = (int)ceil($totalEmployees / $limit);
        if ($totalPages < 1) { $totalPages = 1; }
        if ($currentPage > $totalPages) { $currentPage = $totalPages; $offset = ($currentPage - 1) * $limit; }

        // 2. Traemos solo el segmento correspondiente de la página actual
        $listaEmpleados = $userModel->listEmployees($search, $is_active, $role, $limit, $offset);

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
            $nombre        = trim($_POST['nombre'] ?? '');
            $apellido      = trim($_POST['apellido'] ?? '');
            $email         = trim($_POST['email'] ?? '');
            $telefono      = trim($_POST['telefono'] ?? '');
            $dni           = trim($_POST['dni'] ?? '');
            $cuil          = trim($_POST['cuil'] ?? '');
            $direccion     = trim($_POST['direccion'] ?? '');
            $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '');
            $password      = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            $idRol         = (int)($_POST['id_rol'] ?? 0);
            $idProvincia   = (int)($_POST['id_provincia'] ?? 0);
            $idLocalidad   = (int)($_POST['id_localidad'] ?? 0);
            $idNacionalidad = (int)($_POST['id_nacionalidad'] ?? 0);

            if (empty($nombre)) {
                throw new Exception("El nombre es obligatorio.");
            }
            if (empty($apellido)) {
                throw new Exception("El apellido es obligatorio.");
            }
            if (empty($email)) {
                throw new Exception("El correo electrónico es obligatorio.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("El formato del correo electrónico no es válido.");
            }
            if (empty($password)) {
                throw new Exception("La contraseña es obligatoria.");
            }
            if (strlen($password) < 5) {
                throw new Exception("La contraseña debe tener al menos 5 caracteres.");
            }
            if ($idRol <= 0) {
                throw new Exception("Debe seleccionar un rol para el empleado.");
            }
            if ($idProvincia <= 0) {
                throw new Exception("Debe seleccionar una provincia.");
            }
            if ($idLocalidad <= 0) {
                throw new Exception("Debe seleccionar una localidad.");
            }
            if ($idNacionalidad <= 0) {
                throw new Exception("Debe seleccionar una nacionalidad.");
            }

            if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $nombre)) {
                throw new Exception("El nombre solo puede contener letras y espacios.");
            }
            if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $apellido)) {
                throw new Exception("El apellido solo puede contener letras y espacios.");
            }

            $usuario = new Usuario();
            $usuario->setNombre($nombre);
            $usuario->setApellido($apellido);
            $usuario->setEmail($email);
            $usuario->setPassword($password);

            $usuario->setIdRol($idRol);
            $usuario->setIdProvincia($idProvincia);
            $usuario->setIdLocalidad($idLocalidad);
            $usuario->setIdNacionalidad($idNacionalidad);

            $usuario->setTelefono($telefono);
            $usuario->setDni($dni);
            $usuario->setCuil($cuil);
            $usuario->setDireccion($direccion);
            $usuario->setFechaNacimiento($fechaNacimiento);

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

        // Redirección adaptada dinámicamente con UrlHelper
        header('Location: ' . UrlHelper::to('/dashboard/employees/add'));
        exit;
    }

    public function showEditEmployeeForm(array $vars): void
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

        $id = $vars['id'] ?? '';
        if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
            $_SESSION['flash_message'] = "ID de empleado inválido.";
            $_SESSION['flash_status']  = "error";
            header('Location: ' . UrlHelper::to('/dashboard/employees'));
            exit;
        }

        $userModel = new Usuario();
        $employee = $userModel->findById((int)$id);

        if (!$employee) {
            $_SESSION['flash_message'] = "El empleado solicitado no existe.";
            $_SESSION['flash_status']  = "error";
            header('Location: ' . UrlHelper::to('/dashboard/employees'));
            exit;
        }

        $countries = (new \App\Models\Nacionalidad())->getAll();
        $roles     = (new \App\Models\Rol())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/editEmployee.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function editEmployee(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $_POST['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de empleado inválido.");
            }

            $nombre        = trim($_POST['nombre'] ?? '');
            $apellido      = trim($_POST['apellido'] ?? '');
            $email         = trim($_POST['email'] ?? '');
            $telefono      = trim($_POST['telefono'] ?? '');
            $dni           = trim($_POST['dni'] ?? '');
            $cuil          = trim($_POST['cuil'] ?? '');
            $direccion     = trim($_POST['direccion'] ?? '');
            $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '');
            $idRol         = (int)($_POST['id_rol'] ?? 0);
            $idProvincia   = (int)($_POST['id_provincia'] ?? 0);
            $idLocalidad   = (int)($_POST['id_localidad'] ?? 0);
            $idNacionalidad = (int)($_POST['id_nacionalidad'] ?? 0);

            if (empty($nombre)) {
                throw new Exception("El nombre es obligatorio.");
            }
            if (empty($apellido)) {
                throw new Exception("El apellido es obligatorio.");
            }
            if (empty($email)) {
                throw new Exception("El correo electrónico es obligatorio.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("El formato del correo electrónico no es válido.");
            }
            if ($idRol <= 0) {
                throw new Exception("Debe seleccionar un rol para el empleado.");
            }
            if ($idProvincia <= 0) {
                throw new Exception("Debe seleccionar una provincia.");
            }
            if ($idLocalidad <= 0) {
                throw new Exception("Debe seleccionar una localidad.");
            }
            if ($idNacionalidad <= 0) {
                throw new Exception("Debe seleccionar una nacionalidad.");
            }

            if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $nombre)) {
                throw new Exception("El nombre solo puede contener letras y espacios.");
            }
            if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $apellido)) {
                throw new Exception("El apellido solo puede contener letras y espacios.");
            }

            $usuario = new Usuario();
            $usuario->setId((int)$id);
            $usuario->setNombre($nombre);
            $usuario->setApellido($apellido);
            $usuario->setEmail($email);
            $usuario->setIdRol($idRol);
            $usuario->setIdProvincia($idProvincia);
            $usuario->setIdLocalidad($idLocalidad);
            $usuario->setIdNacionalidad($idNacionalidad);

            $usuario->setTelefono($telefono);
            $usuario->setDni($dni);
            $usuario->setCuil($cuil);
            $usuario->setDireccion($direccion);
            $usuario->setFechaNacimiento($fechaNacimiento);

            $success = $usuario->update($usuario);

            if (!$success) {
                throw new Exception("No se pudo actualizar el empleado. Verifique los datos ingresados.");
            }

            $_SESSION['flash_message'] = "Empleado actualizado exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/employees'));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $redirectId = $_POST['id'] ?? '';
            header('Location: ' . UrlHelper::to('/dashboard/employees/edit?id=' . urlencode((string)$redirectId)));
            exit;
        }
    }

    /**
     * Procesa la baja lógica de un empleado (Inactivación)
     */
    public function deactivateEmployee(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $idToDeactivate = $vars['id'] ?? '';
            
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
            
            header('Location: ' . UrlHelper::to('/dashboard/employees'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/employees'));
            exit;
        }
    }

    /**
     * Procesa la reactivación del acceso de un empleado (Activación)
     */
    public function activateEmployee(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $idToActivate = $vars['id'] ?? '';
            
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
            
            header('Location: ' . UrlHelper::to('/dashboard/employees'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/employees'));
            exit;
        }
    }
    /**
     * Muestra el formulario para cambiarle la contraseña a un empleado desde el Dashboard
     */
    public function showResetPasswordForm(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $flashMessage = $_SESSION['flash_message'] ?? '';
        unset($_SESSION['flash_message']);

        $flashStatus = $_SESSION['flash_status'] ?? '';
        unset($_SESSION['flash_status']);

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $id = $_GET['id'] ?? '';
        if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
            $_SESSION['flash_message'] = "ID de empleado inválido.";
            $_SESSION['flash_status']  = "error";
            header('Location: /sires/dashboard/employees');
            exit;
        }

        $userModel = new Usuario();
        $employee = $userModel->findById((int)$id);

        if (!$employee) {
            $_SESSION['flash_message'] = "El empleado solicitado no existe.";
            $_SESSION['flash_status']  = "error";
            header('Location: /sires/dashboard/employees');
            exit;
        }

        // Definimos la vista intermedia
        $contentView = __DIR__ . '/../views/dashboard/resetPassword.phtml';

        // Cargamos el layout principal de tu dashboard
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    /**
     * Procesa el cambio de contraseña ejecutado por el administrador
     */
    public function resetPassword(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $id = $_POST['id'] ?? '';

        try {
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de empleado inválido.");
            }

            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($password)) {
                throw new Exception("La contraseña es obligatoria.");
            }
            if (strlen($password) < 5) {
                throw new Exception("La contraseña debe tener al menos 5 caracteres.");
            }
            if ($password !== $confirmPassword) {
                throw new Exception("Las contraseñas ingresadas no coinciden.");
            }

            $userModel = new Usuario();
            $employee = $userModel->findById((int)$id);

            if (!$employee) {
                throw new Exception("El empleado no existe en el sistema.");
            }

            // Cambiamos la clave utilizando el método que ya tiene tu Modelo Usuario
            // Nota: changePasswordAdmin es el método que agregamos en el Paso 3
            $success = $employee->changePasswordAdmin($password);

            if (!$success) {
                throw new Exception("No se pudo actualizar la contraseña. Intente nuevamente.");
            }

            $_SESSION['flash_message'] = "Contraseña de " . $employee->getNombre() . " actualizada con éxito.";
            $_SESSION['flash_status']  = "success";

            // Redireccionamos a la lista general de empleados
            header('Location: /sires/dashboard/employees');
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: /sires/dashboard/employees/reset-password?id=' . urlencode((string)$id));
            exit;
        }
    }

}
