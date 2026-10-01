<?php

namespace App\Controllers;

use Exception;
use App\Models\Habitacion;
use App\Models\MotivoBloqueo;
use App\Services\HabitacionService;
use App\Services\LimpiezaService;
use App\Helpers\UrlHelper;

class RoomController
{
    private HabitacionService $habitacionService;
    private LimpiezaService $limpiezaService;

    public function __construct()
    {
        $this->habitacionService = new HabitacionService();
        $this->limpiezaService   = new LimpiezaService();
    }

    public function showRooms(array $vars): void
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

        $search = $vars['search'] ?? "";
        $status = $vars['status_filter'] ?? "";
        $type   = $vars['type_filter'] ?? "";
        $floor  = $vars['floor_filter'] ?? "";

        $hasSearch = !empty($vars['search']);
        $hasStatus = isset($vars['status_filter']) && $vars['status_filter'] !== '';
        $hasType   = isset($vars['type_filter']) && $vars['type_filter'] !== '';
        $hasFloor  = isset($vars['floor_filter']) && $vars['floor_filter'] !== '';

        $roomModel = new Habitacion();
        $habitaciones = $roomModel->getAllWithFilters($search, $status, $type, $floor);
        $tipos = $roomModel->getTiposHabitacion();
        $estados = $roomModel->getEstadosHabitacion();
        $pisos = $roomModel->getPisos();
        $motivos = (new MotivoBloqueo())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/rooms.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function showNewRoomForm(): void
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

        $roomModel = new Habitacion();
        $tipos = $roomModel->getTiposHabitacion();
        $estados = $roomModel->getEstadosHabitacion();
        $pisos = $roomModel->getPisos();
        $todasHabitaciones = $roomModel->getAllWithFilters(null, null, null, null);

        $contentView = __DIR__ . '/../views/dashboard/addRoom.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addRoom(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $piso        = (int)($_POST['piso'] ?? -1);
            $idTipo      = (int)($_POST['id_tipo_habitacion'] ?? 0);
            $precioNoche = (float)($_POST['precio_noche_base'] ?? 0);

            $resultado = $this->habitacionService->altaIndividual($piso, $idTipo, $precioNoche);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms/add'));
            exit;
        }
    }

    public function showEditRoomForm(array $vars): void
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

        try {
            $id = $vars['id'] ?? '';

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de habitación inválido.");
            }

            $roomModel = new Habitacion();
            $habitacion = $roomModel->findById((int)$id);

            if (!$habitacion) {
                throw new Exception("La habitación no existe.");
            }

            $tipos = $roomModel->getTiposHabitacion();
            $estados = $roomModel->getEstadosHabitacion();

            $contentView = __DIR__ . '/../views/dashboard/editRoom.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        }
    }

    public function editRoom(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id          = (int)($_POST['id'] ?? 0);
            $numero      = (int)($_POST['numero'] ?? 0);
            $piso        = (int)($_POST['piso'] ?? -1);
            $idTipo      = (int)($_POST['id_tipo_habitacion'] ?? 0);
            $idEstado    = (int)($_POST['id_estado_habitacion'] ?? 0);
            $precioNoche = (float)($_POST['precio_noche_base'] ?? 0);

            $resultado = $this->habitacionService->editar($id, $numero, $piso, $idTipo, $idEstado, $precioNoche);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms/edit?id=' . ($_POST['id'] ?? 0)));
            exit;
        }
    }

    public function showBatchRoomForm(): void
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

        $roomModel = new Habitacion();
        $tipos = $roomModel->getTiposHabitacion();
        $pisos = $roomModel->getPisos();
        $nextFloor = $roomModel->getNextFloorNumber();

        $contentView = __DIR__ . '/../views/dashboard/addRoomBatch.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addBatchRoom(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $piso        = (int)($_POST['piso'] ?? -1);
            $idTipo      = (int)($_POST['id_tipo_habitacion'] ?? 0);
            $precioNoche = (float)($_POST['precio_noche_base'] ?? 0);
            $numeroDesde = (int)($_POST['numero_desde'] ?? 0);
            $numeroHasta = (int)($_POST['numero_hasta'] ?? 0);

            $resultado = $this->habitacionService->altaMasiva($piso, $idTipo, $precioNoche, $numeroDesde, $numeroHasta);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms/add/batch'));
            exit;
        }
    }

    public function showRoomDetail(array $vars): void
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
                throw new Exception("ID de habitación inválido.");
            }

            $roomModel = new Habitacion();
            $habitacion = $roomModel->findById((int)$id);

            if (!$habitacion) {
                throw new Exception("La habitación no existe.");
            }

            $contentView = __DIR__ . '/../views/dashboard/detailRoom.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        }
    }

    public function deactivateRoom(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';
            $idMotivoBloqueo = $vars['motivo'] ?? '';

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de habitación inválido.");
            }

            $motivoParam = !empty($idMotivoBloqueo) ? (int)$idMotivoBloqueo : null;
            $resultado = $this->habitacionService->bloquear((int)$id, $motivoParam);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        }
    }

    public function activateRoom(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de habitación inválido.");
            }

            $resultado = $this->habitacionService->desbloquear((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        }
    }

    public function bajaLogicaRoom(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de habitación inválido.");
            }

            $resultado = $this->habitacionService->darDeBaja((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        }
    }

    public function reactivateRoom(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de habitación inválido.");
            }

            $resultado = $this->habitacionService->reactivar((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        }
    }

    public function startCleaning(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de habitación inválido.");
            }

            $resultado = $this->limpiezaService->iniciarLimpieza((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        }
    }

    public function completeCleaning(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de habitación inválido.");
            }

            $resultado = $this->limpiezaService->completarLimpieza((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        }
    }

    public function putInMaintenance(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de habitación inválido.");
            }

            $resultado = $this->habitacionService->ponerEnMantenimiento((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        }
    }

    public function outOfMaintenance(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';

            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de habitación inválido.");
            }

            $resultado = $this->habitacionService->salirDeMantenimiento((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/rooms'));
            exit;
        }
    }
}