<?php

namespace App\Controllers;

use Exception;
use App\Models\Reserva;
use App\Models\Habitacion;
use App\Models\Clientes;
use App\Models\CanalOrigen;
use App\Helpers\UrlHelper;

class BookingController
{
    public function showBooking(array $vars): void
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

        $search = $vars['search'] ?? "";
        $estado = $vars['estado_filter'] ?? "";
        $canal  = $vars['canal_filter'] ?? "";

        $hasSearch = !empty($vars['search']);
        $hasEstado = isset($vars['estado_filter']) && $vars['estado_filter'] !== '';
        $hasCanal  = isset($vars['canal_filter']) && $vars['canal_filter'] !== '';

        $reservaModel = new Reserva();

        $totalReservas = $reservaModel->countAllWithFilters($search, $estado, $canal);
        $totalPages = (int)ceil($totalReservas / $limit);
        if ($totalPages < 1) { $totalPages = 1; }
        if ($currentPage > $totalPages) { $currentPage = $totalPages; $offset = ($currentPage - 1) * $limit; }

        $reservas = $reservaModel->getAllWithFilters($search, $estado, $canal, $limit, $offset);
        $estadosReserva = $reservaModel->getEstadosReserva();
        $canalesOrigen  = $reservaModel->getCanalesOrigen();

        $contentView = __DIR__ . '/../views/dashboard/bookings.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function showNewBookingForm(): void
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

        $clientes     = (new Clientes())->getAll();
        $habitaciones = (new Habitacion())->getAllWithFilters(null, null, null, null);
        $canales      = (new CanalOrigen())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/addBooking.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addBooking(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $idCliente      = (int)($_POST['id_cliente'] ?? 0);
            $idHabitacion   = (int)($_POST['id_habitacion'] ?? 0);
            $idCanal        = (int)($_POST['id_canal_origen'] ?? 0);
            $fechaEntrada   = trim($_POST['fecha_entrada'] ?? '');
            $fechaSalida    = trim($_POST['fecha_salida'] ?? '');
            $cantHuespedes  = (int)($_POST['cantidad_huespedes'] ?? 1);
            $observaciones  = trim($_POST['observaciones'] ?? '');

            if (empty($fechaEntrada)) {
                throw new Exception("La fecha de entrada es obligatoria.");
            }
            if (empty($fechaSalida)) {
                throw new Exception("La fecha de salida es obligatoria.");
            }
            if ($fechaSalida <= $fechaEntrada) {
                throw new Exception("La fecha de salida debe ser posterior a la fecha de entrada.");
            }

            $reserva = new Reserva();
            $reserva->setIdCliente($idCliente);
            $reserva->setIdHabitacion($idHabitacion);
            $reserva->setIdCanalOrigen($idCanal);
            $reserva->setFechaEntrada($fechaEntrada);
            $reserva->setFechaSalida($fechaSalida);
            $reserva->setCantidadHuespedes($cantHuespedes);
            $reserva->setObservaciones($observaciones ?: null);
            $reserva->setCreadoPor($_SESSION['user_id'] ?? 0);

            $success = (new Reserva())->save($reserva);

            if (!$success) {
                throw new Exception("No se pudo registrar la reserva. Verifique los datos ingresados.");
            }

            $_SESSION['flash_message'] = "Reserva registrada exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/booking'));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/booking/add'));
            exit;
        }
    }

    public function showEditBookingForm(array $vars): void
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
                throw new Exception("ID de reserva inválido.");
            }

            $reservaModel = new Reserva();
            $reserva = $reservaModel->findById((int)$id);

            if (!$reserva) {
                throw new Exception("La reserva no existe.");
            }

            if ((int)$reserva['id_estado_reserva'] === Reserva::ESTADO_CANCELADA ||
                (int)$reserva['id_estado_reserva'] === Reserva::ESTADO_FINALIZADA) {
                throw new Exception("No se puede editar una reserva cancelada o finalizada.");
            }

            $clientes     = (new Clientes())->getAll();
            $habitaciones = (new Habitacion())->getAllWithFilters(null, null, null, null);
            $canales      = (new CanalOrigen())->getAll();

            $contentView = __DIR__ . '/../views/dashboard/editBooking.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/booking'));
            exit;
        }
    }

    public function editBooking(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id             = (int)($_POST['id'] ?? 0);
            $idCliente      = (int)($_POST['id_cliente'] ?? 0);
            $idHabitacion   = (int)($_POST['id_habitacion'] ?? 0);
            $idCanal        = (int)($_POST['id_canal_origen'] ?? 0);
            $fechaEntrada   = trim($_POST['fecha_entrada'] ?? '');
            $fechaSalida    = trim($_POST['fecha_salida'] ?? '');
            $cantHuespedes  = (int)($_POST['cantidad_huespedes'] ?? 1);
            $observaciones  = trim($_POST['observaciones'] ?? '');

            if ($id <= 0) {
                throw new Exception("Ocurrió un error al seleccionar la reserva.");
            }
            if (empty($fechaEntrada)) {
                throw new Exception("La fecha de entrada es obligatoria.");
            }
            if (empty($fechaSalida)) {
                throw new Exception("La fecha de salida es obligatoria.");
            }
            if ($fechaSalida <= $fechaEntrada) {
                throw new Exception("La fecha de salida debe ser posterior a la fecha de entrada.");
            }

            $reserva = new Reserva();
            $reserva->setId($id);
            $reserva->setIdCliente($idCliente);
            $reserva->setIdHabitacion($idHabitacion);
            $reserva->setIdCanalOrigen($idCanal);
            $reserva->setFechaEntrada($fechaEntrada);
            $reserva->setFechaSalida($fechaSalida);
            $reserva->setCantidadHuespedes($cantHuespedes);
            $reserva->setObservaciones($observaciones ?: null);

            $success = (new Reserva())->update($reserva);

            if (!$success) {
                throw new Exception("No se pudo actualizar la reserva. Solo se pueden editar reservas pendientes o confirmadas.");
            }

            $_SESSION['flash_message'] = "Reserva actualizada exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/booking'));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/booking/edit?id=' . ($_POST['id'] ?? 0)));
            exit;
        }
    }

    public function showBookingDetail(array $vars): void
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
                throw new Exception("ID de reserva inválido.");
            }

            $reserva = (new Reserva())->findById((int)$id);
            if (!$reserva) {
                throw new Exception("La reserva no existe.");
            }

            $contentView = __DIR__ . '/../views/dashboard/detailBooking.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/booking'));
            exit;
        }
    }

    public function cancelBooking(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $reservaModel = new Reserva();
            $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_CANCELADA, Reserva::ESTADO_PENDIENTE);

            if (!$success) {
                $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_CANCELADA, Reserva::ESTADO_CONFIRMADA);
            }

            if (!$success) {
                throw new Exception("No se pudo cancelar la reserva. Solo se pueden cancelar reservas pendientes o confirmadas.");
            }

            $_SESSION['flash_message'] = "Reserva cancelada exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/booking'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/booking'));
            exit;
        }
    }

    public function confirmBooking(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $reservaModel = new Reserva();
            $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_CONFIRMADA, Reserva::ESTADO_PENDIENTE);

            if (!$success) {
                throw new Exception("No se pudo confirmar la reserva. Solo se pueden confirmar reservas pendientes.");
            }

            $_SESSION['flash_message'] = "Reserva confirmada exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/booking'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/booking'));
            exit;
        }
    }
}
