<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Nacionalidad;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\EmpleadoService;

class EmployeeController extends Controller
{
    private const ID_INVALIDO = 'ID de empleado inválido.';

    /** Empleados por página en el listado. */
    private const POR_PAGINA = 5;

    private EmpleadoService $empleados;

    public function __construct()
    {
        $this->empleados = new EmpleadoService();
    }

    public function showEmployees(array $vars): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');
        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $search    = $vars['search'] ?? "";
        $role      = $vars['role_filter'] ?? "";
        $is_active = $vars['status_filter'] ?? "";

        $hasSearch = !empty($vars['search']);
        $hasRole   = !empty($role);
        $hasStatus = isset($vars['status_filter']) && $vars['status_filter'] !== '';

        $roles = (new Rol())->getAll();
        $userModel = new Usuario();

        $totalEmployees = $userModel->countEmployees($search, $is_active, $role);
        $totalPages  = max(1, (int)ceil($totalEmployees / self::POR_PAGINA));
        $currentPage = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);

        $listaEmpleados = $userModel->listEmployees($search, $is_active, $role, self::POR_PAGINA,
            ($currentPage - 1) * self::POR_PAGINA);

        $contentView = __DIR__ . '/../views/dashboard/employees.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function showNewEmployeeForm(): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');
        [$flashMessage, $flashStatus] = $this->tomarFlash();
        $old = $this->tomarInputs();

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $countries = (new Nacionalidad())->getAll();
        $roles     = (new Rol())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/addEmployee.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addEmployee(): void
    {
        $this->ejecutarAccion(function (): array {
            csrf_check();
            return $this->empleados->alta($_POST);
        }, '/dashboard/employees/add', null, true);
    }

    public function showEditEmployeeForm(array $vars): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');
        [$flashMessage, $flashStatus] = $this->tomarFlash();
        $old = $this->tomarInputs();

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $employee = $this->empleadoOVolver($vars);

        $countries = (new Nacionalidad())->getAll();
        $roles     = (new Rol())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/editEmployee.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function editEmployee(): void
    {
        $this->ejecutarAccion(function (): array {
            csrf_check();
            return $this->empleados->editar($this->idDe('id', self::ID_INVALIDO), $_POST);
        }, '/dashboard/employees', '/dashboard/employees/edit?id=' . urlencode((string)($_POST['id'] ?? '')), true);
    }

    /** Baja lógica (el empleado deja de poder entrar). CSRF: lo verifica el middleware de la ruta. */
    public function deactivateEmployee(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->empleados->desactivar($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/employees'
        );
    }

    /** Reactivación del acceso. CSRF: lo verifica el middleware de la ruta. */
    public function activateEmployee(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->empleados->activar($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/employees'
        );
    }

    /** Formulario para que un administrador le cambie la contraseña a un empleado. */
    public function showResetPasswordForm(array $vars = []): void
    {
        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $employee = $this->empleadoOVolver($_GET);

        $contentView = __DIR__ . '/../views/dashboard/resetPassword.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function resetPassword(): void
    {
        $this->ejecutarAccion(function (): array {
            csrf_check();
            return $this->empleados->cambiarPassword(
                $this->idDe('id', self::ID_INVALIDO),
                (string)($_POST['password'] ?? ''),
                (string)($_POST['confirm_password'] ?? '')
            );
        }, '/dashboard/employees', '/dashboard/employees/reset-password?id=' . urlencode((string)($_POST['id'] ?? '')));
    }

    /** Empleado del id de $origen; si el id no es válido o no existe, vuelve al listado con error. */
    private function empleadoOVolver(array $origen): Usuario
    {
        $id = filter_var($origen['id'] ?? '', FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            $this->flash(self::ID_INVALIDO, 'error');
            $this->redirect('/dashboard/employees');
        }
        $empleado = (new Usuario())->findById($id);
        if ($empleado === null) {
            $this->flash('El empleado solicitado no existe.', 'error');
            $this->redirect('/dashboard/employees');
        }
        return $empleado;
    }
}
