<?php

namespace App\Controllers;

use Exception;
use App\Models\Housekeeping;
use App\Models\Habitacion;
use App\Models\Usuario;
use App\Helpers\UrlHelper;

class HousekeepingController
{
    public function showHousekeeping(array $vars): void
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

        $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($currentPage < 1) { $currentPage = 1; }

        $limit = 10;
        $offset = ($currentPage - 1) * $limit;

        $estado = $vars['estado_filter'] ?? "";
        $tipo = $vars['tipo_filter'] ?? "";
        $fecha = $vars['fecha_filter'] ?? "";

        $hasEstado = isset($vars['estado_filter']) && $vars['estado_filter'] !== '';
        $hasTipo = isset($vars['tipo_filter']) && $vars['tipo_filter'] !== '';
        $hasFecha = isset($vars['fecha_filter']) && $vars['fecha_filter'] !== '';

        $housekeepingModel = new Housekeeping();

        $totalTareas = $housekeepingModel->countAllWithFilters($estado, $tipo, $fecha);
        $totalPages = (int)ceil($totalTareas / $limit);
        if ($totalPages < 1) { $totalPages = 1; }
        if ($currentPage > $totalPages) { $currentPage = $totalPages; $offset = ($currentPage - 1) * $limit; }

        $tareas = $housekeepingModel->getAllWithFilters($estado, $tipo, $fecha, $limit, $offset);
        $estadosTarea = $housekeepingModel->getEstadosTarea();
        $tiposTarea = $housekeepingModel->getTiposTarea();
        $insumosBajoStock = $housekeepingModel->getInsumosBajoStock();

        $contentView = __DIR__ . '/../views/dashboard/housekeeping.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function showNewTaskForm(): void
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

        $habitaciones = (new Habitacion())->getAllWithFilters(null, null, null, null);
        $empleados = (new Usuario())->getByRole(5); // Rol Mantenimiento
        $tiposTarea = (new Housekeeping())->getTiposTarea();

        $contentView = __DIR__ . '/../views/dashboard/addHousekeeping.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addTask(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $idHabitacion = (int)($_POST['id_habitacion'] ?? 0);
            $idEmpleado = (int)($_POST['id_empleado'] ?? 0);
            $tipoTarea = $_POST['tipo_tarea'] ?? '';
            $fechaAsignada = $_POST['fecha_asignada'] ?? '';
            $observaciones = trim($_POST['observaciones'] ?? '');

            if ($idHabitacion <= 0) {
                throw new Exception("Debe seleccionar una habitación.");
            }

            if (empty($tipoTarea)) {
                throw new Exception("Debe seleccionar un tipo de tarea.");
            }

            if (empty($fechaAsignada)) {
                throw new Exception("La fecha de asignación es obligatoria.");
            }

            $tarea = new Housekeeping();
            $tarea->setIdHabitacion($idHabitacion);
            $tarea->setIdEmpleadoAsignado($idEmpleado > 0 ? $idEmpleado : null);
            $tarea->setTipoTarea($tipoTarea);
            $tarea->setFechaAsignada($fechaAsignada);
            $tarea->setObservaciones($observaciones ?: null);

            $housekeepingModel = new Housekeeping();
            $success = $housekeepingModel->save($tarea);

            if (!$success) {
                throw new Exception("No se pudo registrar la tarea. Verifique los datos ingresados.");
            }

            $_SESSION['flash_message'] = "Tarea de housekeeping registrada exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/housekeeping'));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/housekeeping/add'));
            exit;
        }
    }

    public function showTaskDetail(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        try {
            $id = $vars['id'] ?? '';

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de tarea inválido.");
            }

            $housekeepingModel = new Housekeeping();
            $tarea = $housekeepingModel->findById((int)$id);

            if (!$tarea) {
                throw new Exception("La tarea no existe.");
            }

            $contentView = __DIR__ . '/../views/dashboard/detailHousekeeping.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/housekeeping'));
            exit;
        }
    }

    public function completeTask(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $id = $_POST['id'] ?? $vars['id'] ?? null;

            if ($id === null || $id === '' || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de tarea inválido.");
            }

            $housekeepingModel = new Housekeeping();
            if (!$housekeepingModel->completar((int)$id)) {
                throw new Exception("No se pudo completar la tarea.");
            }

            $_SESSION['flash_message'] = "Tarea completada exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/housekeeping'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/housekeeping'));
            exit;
        }
    }

    public function inspectTask(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $id = $_POST['id'] ?? $vars['id'] ?? null;

            if ($id === null || $id === '' || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de tarea inválido.");
            }

            $housekeepingModel = new Housekeeping();
            if (!$housekeepingModel->inspeccionar((int)$id)) {
                throw new Exception("No se pudo inspeccionar la tarea.");
            }

            $_SESSION['flash_message'] = "Tarea inspeccionada exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/housekeeping'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/housekeeping'));
            exit;
        }
    }

    public function showInsumos(): void
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

        $housekeepingModel = new Housekeeping();
        $insumos = $housekeepingModel->getInsumos();

        $contentView = __DIR__ . '/../views/dashboard/insumos.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function updateStock(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $idInsumo = (int)($_POST['id_insumo'] ?? 0);
            $cantidad = (int)($_POST['cantidad'] ?? 0);

            if ($idInsumo <= 0) {
                throw new Exception("Debe seleccionar un insumo.");
            }

            if ($cantidad === 0) {
                throw new Exception("La cantidad no puede ser cero.");
            }

            $housekeepingModel = new Housekeeping();
            if (!$housekeepingModel->actualizarStock($idInsumo, $cantidad)) {
                throw new Exception("No se pudo actualizar el stock.");
            }

            $_SESSION['flash_message'] = "Stock actualizado exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/housekeeping/insumos'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/housekeeping/insumos'));
            exit;
        }
    }
}
