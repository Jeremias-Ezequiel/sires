<?php

namespace App\Controllers;

use Exception;
use App\Models\Habitacion;
use App\Helpers\UrlHelper;

class RoomController
{
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

        $contentView = __DIR__ . '/../views/dashboard/addRoom.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addRoom(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $numero      = $_POST['numero'] ?? '';
            $piso        = $_POST['piso'] ?? '';
            $idTipo      = $_POST['id_tipo_habitacion'] ?? 0;
            $idEstado    = $_POST['id_estado_habitacion'] ?? 0;
            $precioNoche = $_POST['precio_noche_base'] ?? '';

            if (empty($numero) || (int)$numero <= 0) {
                throw new Exception("El número de habitación es obligatorio y debe ser mayor a 0.");
            }

            if ($piso === '' || (int)$piso < 0) {
                throw new Exception("El piso es obligatorio y no puede ser negativo.");
            }

            if ((int)$idTipo <= 0) {
                throw new Exception("Debe seleccionar un tipo de habitación.");
            }

            if ((int)$idEstado <= 0) {
                throw new Exception("Debe seleccionar un estado para la habitación.");
            }

            if ($precioNoche === '' || (float)$precioNoche < 0) {
                throw new Exception("El precio por noche es obligatorio y no puede ser negativo.");
            }

            $habitacion = new Habitacion();
            $habitacion->setNumero((int)$numero);
            $habitacion->setPiso((int)$piso);
            $habitacion->setIdTipoHabitacion((int)$idTipo);
            $habitacion->setIdEstadoHabitacion((int)$idEstado);
            $habitacion->setPrecioNocheBase((float)$precioNoche);

            $success = $habitacion->save($habitacion);

            if (!$success) {
                throw new Exception("No se pudo registrar la habitación. Verifique los datos ingresados.");
            }

            $_SESSION['flash_message'] = "Habitación registrada exitosamente.";
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
            $id          = $_POST['id'] ?? 0;
            $numero      = $_POST['numero'] ?? '';
            $piso        = $_POST['piso'] ?? '';
            $idTipo      = $_POST['id_tipo_habitacion'] ?? 0;
            $idEstado    = $_POST['id_estado_habitacion'] ?? 0;
            $precioNoche = $_POST['precio_noche_base'] ?? '';

            if (empty($id) || (int)$id <= 0) {
                throw new Exception("Ocurrió un error al seleccionar la habitación.");
            }

            if (empty($numero) || (int)$numero <= 0) {
                throw new Exception("El número de habitación es obligatorio y debe ser mayor a 0.");
            }

            if ($piso === '' || (int)$piso < 0) {
                throw new Exception("El piso es obligatorio y no puede ser negativo.");
            }

            if ((int)$idTipo <= 0) {
                throw new Exception("Debe seleccionar un tipo de habitación.");
            }

            if ((int)$idEstado <= 0) {
                throw new Exception("Debe seleccionar un estado para la habitación.");
            }

            if ($precioNoche === '' || (float)$precioNoche < 0) {
                throw new Exception("El precio por noche es obligatorio y no puede ser negativo.");
            }

            $habitacion = new Habitacion();
            $habitacion->setId((int)$id);
            $habitacion->setNumero((int)$numero);
            $habitacion->setPiso((int)$piso);
            $habitacion->setIdTipoHabitacion((int)$idTipo);
            $habitacion->setIdEstadoHabitacion((int)$idEstado);
            $habitacion->setPrecioNocheBase((float)$precioNoche);

            $success = $habitacion->update($habitacion);

            if (!$success) {
                throw new Exception("No se pudo actualizar la habitación.");
            }

            $_SESSION['flash_message'] = "Habitación actualizada exitosamente.";
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

            if (empty($id)) {
                throw new Exception("Ocurrió un error al seleccionar la habitación. Intente nuevamente.");
            }

            if (filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("El ID tiene que ser estrictamente un número entero.");
            }

            $roomModel = new Habitacion();
            if (!$roomModel->deactivate((int)$id)) {
                throw new Exception("La habitación no existe, ya se encuentra bloqueada o no está disponible.");
            }

            $_SESSION['flash_message'] = "Habitación bloqueada exitosamente.";
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

            if (empty($id)) {
                throw new Exception("Ocurrió un error al seleccionar la habitación. Intente nuevamente.");
            }

            if (filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("El ID tiene que ser estrictamente un número entero.");
            }

            $roomModel = new Habitacion();
            if (!$roomModel->activate((int)$id)) {
                throw new Exception("La habitación no existe o ya se encuentra disponible.");
            }

            $_SESSION['flash_message'] = "Habitación reactivada exitosamente.";
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