<?php

namespace App\Controllers;

use Exception;
use App\Models\Reserva;
use App\Models\Habitacion;
use App\Models\Clientes;
use App\Models\CanalOrigen;
use App\Models\ResumenPago;
use App\Services\ReservaService;
use App\Services\PagoService;
use App\Helpers\UrlHelper;

class BookingController
{
    private ReservaService $reservaService;
    private PagoService $pagoService;

    public function __construct()
    {
        $this->reservaService = new ReservaService();
        $this->pagoService    = new PagoService();
    }

    private function redirect(string $url): void
    {
        header('Location: ' . UrlHelper::to($url));
        exit;
    }

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
        $habitaciones = array_filter(
            (new Habitacion())->getAllWithFilters(null, null, null, null),
            function (array $h): bool {
                return (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_MANTENIMIENTO
                    && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_BLOQUEADA
                    && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_OCUPADA
                    && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_SUCIA
                    && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_LIMPIANDO;
            }
        );
        $reservasActivas = (new Reserva())->getReservasActivas();
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
            $datos = [
                'id_cliente'         => (int)($_POST['id_cliente'] ?? 0),
                'id_habitacion'      => (int)($_POST['id_habitacion'] ?? 0),
                'id_canal_origen'    => (int)($_POST['id_canal_origen'] ?? 0),
                'fecha_entrada'      => trim($_POST['fecha_entrada'] ?? ''),
                'fecha_salida'       => trim($_POST['fecha_salida'] ?? ''),
                'cantidad_huespedes' => (int)($_POST['cantidad_huespedes'] ?? 1),
                'observaciones'      => trim($_POST['observaciones'] ?? ''),
                'creado_por'         => $_SESSION['user_id'] ?? 0,
            ];

            $resultado = $this->reservaService->crear($datos);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking/add');
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

            $habitacionActual = (new Habitacion())->findById((int)$reserva['id_habitacion']);
            if ($habitacionActual !== null && (int)$habitacionActual['id_estado_habitacion'] === Habitacion::ESTADO_OCUPADA) {
                throw new Exception("No se puede editar una reserva cuyo check-in ya fue realizado.");
            }

            $clientes     = (new Clientes())->getAll();
            $habitaciones = array_filter(
                (new Habitacion())->getAllWithFilters(null, null, null, null),
                function (array $h): bool {
                    return (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_MANTENIMIENTO
                        && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_BLOQUEADA
                        && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_OCUPADA
                        && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_SUCIA
                        && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_LIMPIANDO;
                }
            );
            $reservasActivas = (new Reserva())->getReservasActivas();
            $canales      = (new CanalOrigen())->getAll();

            $contentView = __DIR__ . '/../views/dashboard/editBooking.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking');
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

            $habitacionRow = (new Habitacion())->findById($idHabitacion);
            if (!$habitacionRow) {
                throw new Exception("La habitación seleccionada no existe.");
            }

            $reservaModel = new Reserva();
            $reservaActual = $reservaModel->findById($id);
            if (!$reservaActual) {
                throw new Exception("La reserva no existe.");
            }

            $habitacionActual = (new Habitacion())->findById((int)$reservaActual['id_habitacion']);
            if ($habitacionActual !== null && (int)$habitacionActual['id_estado_habitacion'] === Habitacion::ESTADO_OCUPADA) {
                throw new Exception("No se puede editar una reserva cuyo check-in ya fue realizado.");
            }

            if ((int)$reservaActual['id_habitacion'] !== $idHabitacion
                && ((int)$habitacionRow['id_estado_habitacion'] === Habitacion::ESTADO_MANTENIMIENTO
                    || (int)$habitacionRow['id_estado_habitacion'] === Habitacion::ESTADO_BLOQUEADA
                    || (int)$habitacionRow['id_estado_habitacion'] === Habitacion::ESTADO_SUCIA
                    || (int)$habitacionRow['id_estado_habitacion'] === Habitacion::ESTADO_LIMPIANDO)) {
                throw new Exception("La habitación seleccionada no está disponible.");
            }

            if ($reservaModel->existeSolapamiento($idHabitacion, $fechaEntrada, $fechaSalida, $id)) {
                throw new Exception("La habitación ya tiene una reserva pendiente o confirmada para ese rango de fechas.");
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

            $success = $reservaModel->update($reserva);

            if (!$success) {
                throw new Exception("No se pudo actualizar la reserva.");
            }

            $reservaData = $reservaModel->findById($id);
            if ($reservaData !== null) {
                $this->pagoService->recalcularResumen($reservaData);
                $_SESSION['flash_message'] = "Reserva actualizada y resumen de pago recalculado exitosamente.";
                $_SESSION['flash_status']  = "success";
            }

            if (empty($_SESSION['flash_message'])) {
                $_SESSION['flash_message'] = "Reserva actualizada exitosamente.";
                $_SESSION['flash_status']  = "success";
            }

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking/edit?id=' . ($_POST['id'] ?? 0));
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

            $this->redirect('/dashboard/booking');
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

            $resultado = $this->reservaService->cancelar((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking');
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

            $resultado = $this->reservaService->confirmar((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking');
        }
    }

    public function checkInBooking(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $resultado = $this->reservaService->checkIn((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking');
        }
    }

    public function markNoShow(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $resultado = $this->reservaService->marcarNoShow((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking');
        }
    }

    public function finalizeBooking(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $resultado = $this->reservaService->checkOut((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking');
        }
    }

    public function earlyCheckoutBooking(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $id = $vars['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $resultado = $this->reservaService->checkoutAnticipado((int)$id);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking');
        }
    }

    public function moveRoomBooking(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $idReserva  = (int)($vars['id'] ?? 0);
            $idNuevaHab = (int)($vars['nueva_habitacion'] ?? 0);

            if ($idReserva <= 0 || $idNuevaHab <= 0) {
                throw new Exception("Parámetros inválidos para el traslado.");
            }

            $resultado = $this->reservaService->moverHabitacion($idReserva, $idNuevaHab);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            $this->redirect('/dashboard/booking/detail?id=' . $idReserva);
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking');
        }
    }

    public function extendBooking(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $idReserva = (int)($vars['id'] ?? 0);
            $nuevaSalida = trim($vars['nueva_fecha_salida'] ?? '');

            if ($idReserva <= 0 || empty($nuevaSalida)) {
                throw new Exception("Parámetros inválidos para la extensión.");
            }

            $resultado = $this->reservaService->extenderEstadia($idReserva, $nuevaSalida);

            $_SESSION['flash_message'] = $resultado['message'];
            $_SESSION['flash_status']  = "success";

            $this->redirect('/dashboard/booking/detail?id=' . $idReserva);
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $this->redirect('/dashboard/booking');
        }
    }
}