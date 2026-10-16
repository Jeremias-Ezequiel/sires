<?php

namespace App\Controllers;

use Exception;
use App\Models\Usuario;
use App\Models\Rol;
use App\Models\Nacionalidad;
use App\Models\Provincia;
use App\Models\Localidad;
use App\Helpers\Validator;
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
            csrf_check();

            $data = $this->validarYNormalizarEmpleado($_POST, true);

            $usuario = new Usuario();
            $usuario->setNombre($data['nombre']);
            $usuario->setApellido($data['apellido']);
            $usuario->setEmail($data['email']);
            $usuario->setPassword($data['password']);

            $usuario->setIdRol($data['id_rol']);
            $usuario->setIdProvincia($data['id_provincia']);
            $usuario->setIdLocalidad($data['id_localidad']);
            $usuario->setIdNacionalidad($data['id_nacionalidad']);

            $usuario->setTelefono($data['telefono']);
            $usuario->setDni($data['dni']);
            $usuario->setCuil($data['cuil']);
            $usuario->setDireccion($data['direccion']);
            $usuario->setFechaNacimiento($data['fecha_nacimiento']);

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
            csrf_check();

            $id = $_POST['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de empleado inválido.");
            }

            $data = $this->validarYNormalizarEmpleado($_POST, false);

            $usuario = new Usuario();
            $usuario->setId((int)$id);
            $usuario->setNombre($data['nombre']);
            $usuario->setApellido($data['apellido']);
            $usuario->setEmail($data['email']);
            $usuario->setIdRol($data['id_rol']);
            $usuario->setIdProvincia($data['id_provincia']);
            $usuario->setIdLocalidad($data['id_localidad']);
            $usuario->setIdNacionalidad($data['id_nacionalidad']);

            $usuario->setTelefono($data['telefono']);
            $usuario->setDni($data['dni']);
            $usuario->setCuil($data['cuil']);
            $usuario->setDireccion($data['direccion']);
            $usuario->setFechaNacimiento($data['fecha_nacimiento']);

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
     * Valida y normaliza los datos de un empleado provenientes del formulario.
     * Aplica reglas de empresa: DNI argentino, CUIL con dígito verificador
     * (obligatorio solo para argentinos), teléfono E.164 y consistencia de la
     * cascada nacionalidad / provincia / localidad.
     *
     * @throws Exception cuando algún dato no supera la validación.
     */
    private function validarYNormalizarEmpleado(array $post, bool $requierePassword): array
    {
        $nombre          = trim($post['nombre'] ?? '');
        $apellido        = trim($post['apellido'] ?? '');
        $email           = trim($post['email'] ?? '');
        $telefono        = trim($post['telefono'] ?? '');
        $dni             = trim($post['dni'] ?? '');
        $cuil            = trim($post['cuil'] ?? '');
        $direccion       = trim($post['direccion'] ?? '');
        $fechaNacimiento = trim($post['fecha_nacimiento'] ?? '');
        $password        = $post['password'] ?? '';
        $confirmPassword = $post['confirm_password'] ?? '';
        $idRol           = (int)($post['id_rol'] ?? 0);
        $idProvincia     = (int)($post['id_provincia'] ?? 0);
        $idLocalidad     = !empty($post['id_localidad']) ? (int)$post['id_localidad'] : null;
        $idNacionalidad  = (int)($post['id_nacionalidad'] ?? 0);

        // --- Textos libres ---
        Validator::letrasYEspacios($nombre, 'nombre', Validator::MAX_NOMBRE);
        Validator::letrasYEspacios($apellido, 'apellido', Validator::MAX_APELLIDO);
        $email           = Validator::emailValido($email);
        $telefono        = Validator::telefonoE164($telefono);
        $direccion       = Validator::direccionValida($direccion);
        $fechaNacimiento = $fechaNacimiento !== '' ? Validator::fechaNacimientoValida($fechaNacimiento) : '';
        $dni             = $dni !== '' ? Validator::dniEmpleado($dni) : '';

        // --- Selects obligatorios ---
        if ($idRol <= 0) {
            throw new Exception("Debe seleccionar un rol para el empleado.");
        }
        if ($idNacionalidad <= 0) {
            throw new Exception("Debe seleccionar una nacionalidad.");
        }
        if ($idProvincia <= 0) {
            throw new Exception("Debe seleccionar una provincia.");
        }

        // --- Existencia y coherencia de la cascada geográfica ---
        $nacionalidadModel = new Nacionalidad();
        if (!$nacionalidadModel->exists($idNacionalidad)) {
            throw new Exception("La nacionalidad seleccionada no es válida.");
        }

        $provincias = (new Provincia())->getByPais($idNacionalidad) ?? [];
        $provinciaValida = false;
        foreach ($provincias as $prov) {
            if ((int)$prov->getId() === $idProvincia) {
                $provinciaValida = true;
                break;
            }
        }
        if (!$provinciaValida) {
            throw new Exception("La provincia seleccionada no corresponde a la nacionalidad elegida.");
        }

        if ($idLocalidad !== null) {
            $localidades = (new Localidad())->getByProvincia($idProvincia) ?? [];
            $localidadValida = false;
            foreach ($localidades as $loc) {
                if ((int)$loc->getId() === $idLocalidad) {
                    $localidadValida = true;
                    break;
                }
            }
            if (!$localidadValida) {
                throw new Exception("La localidad seleccionada no corresponde a la provincia elegida.");
            }
        }

        // --- CUIL: obligatorio y con dígito verificador para argentinos; opcional para extranjeros ---
        if ($nacionalidadModel->esArgentina($idNacionalidad)) {
            if ($cuil === '') {
                throw new Exception("El CUIL es obligatorio para empleados argentinos.");
            }
            $cuil = Validator::cuilValido($cuil);
        } else {
            $cuil = $cuil !== '' ? Validator::cuilValido($cuil) : '';
        }

        // --- Credenciales (solo en alta) ---
        if ($requierePassword) {
            Validator::passwordValida($password);
            if ($password !== $confirmPassword) {
                throw new Exception("Las contraseñas ingresadas no coinciden.");
            }
        }

        return [
            'nombre'           => $nombre,
            'apellido'         => $apellido,
            'email'            => $email,
            'telefono'         => $telefono,
            'dni'              => $dni,
            'cuil'             => $cuil,
            'direccion'        => $direccion,
            'fecha_nacimiento' => $fechaNacimiento,
            'password'         => $password,
            'id_rol'           => $idRol,
            'id_provincia'     => $idProvincia,
            'id_localidad'     => $idLocalidad,
            'id_nacionalidad'  => $idNacionalidad,
        ];
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
            csrf_check();

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de empleado inválido.");
            }

            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($password)) {
                throw new Exception("La contraseña es obligatoria.");
            }
            if (strlen($password) < 8) {
                throw new Exception("La contraseña debe tener al menos 8 caracteres.");
            }
            if (!preg_match('/[A-Z]/', $password)) {
                throw new Exception("La contraseña debe contener al menos una letra mayúscula.");
            }
            if (!preg_match('/\d/', $password)) {
                throw new Exception("La contraseña debe contener al menos un número.");
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
            header('Location: ' . UrlHelper::to('/dashboard/employees'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/employees/reset-password?id=' . urlencode((string)$id)));
            exit;
        }
    }
    
}
