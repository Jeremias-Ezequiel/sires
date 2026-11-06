<?php

namespace App\Controllers;

use Exception;
use App\Models\Reserva;
use App\Models\Habitacion;
use App\Models\Clientes;
use App\Models\CanalOrigen;
use App\Services\ReservaService;

class BookingController extends Controller
{
    private ReservaService $reservaService;

    public function __construct()
    {
        $this->reservaService = new ReservaService();
    }

    public function showBooking(array $vars): void
    {
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

        // Filtro que llega desde una alerta del inicio (solo valores conocidos)
        $alerta = (string)($vars['alerta'] ?? '');
        if (!isset(Reserva::ALERTAS[$alerta])) { $alerta = ''; }
        $hasAlerta = $alerta !== '';
        $alertaTexto = $hasAlerta ? Reserva::ALERTAS[$alerta] : '';

        $reservaModel = new Reserva();

        $totalReservas = $reservaModel->countAllWithFilters($search, $estado, $canal, $alerta);
        $totalPages = (int)ceil($totalReservas / $limit);
        if ($totalPages < 1) { $totalPages = 1; }
        if ($currentPage > $totalPages) { $currentPage = $totalPages; $offset = ($currentPage - 1) * $limit; }

        $reservas = $reservaModel->getAllWithFilters($search, $estado, $canal, $limit, $offset, $alerta);
        $estadosReserva = $reservaModel->getEstadosReserva();
        $canalesOrigen  = $reservaModel->getCanalesOrigen();

        $contentView = __DIR__ . '/../views/dashboard/bookings.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function showNewBookingForm(): void
    {
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
            fn (array $h): bool => ReservaService::habitacionReservable((int)$h['id_estado_habitacion'])
        );
        $reservasActivas = (new Reserva())->getReservasActivas();
        $canales      = (new CanalOrigen())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/addBooking.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addBooking(): void
    {
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

            $this->flash($resultado['message']);

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $this->recordarInputs($_POST);
            $this->flash($e->getMessage(), 'error');

            $this->redirect('/dashboard/booking/add');
        }
    }

    public function showEditBookingForm(array $vars): void
    {
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

            $estadoReserva = (int)$reserva['id_estado_reserva'];
            if ($estadoReserva === Reserva::ESTADO_EN_CASA) {
                throw new Exception("No se puede editar una reserva cuyo check-in ya fue realizado.");
            }
            if ($estadoReserva !== Reserva::ESTADO_PENDIENTE && $estadoReserva !== Reserva::ESTADO_CONFIRMADA) {
                throw new Exception("Solo se pueden editar reservas pendientes o confirmadas.");
            }

            $clientes     = (new Clientes())->getAll();
            $habitaciones = array_filter(
                (new Habitacion())->getAllWithFilters(null, null, null, null),
                // La habitación actual de la reserva se muestra siempre, aunque hoy no sea reservable.
                fn (array $h): bool => (int)$h['id'] === (int)$reserva['id_habitacion']
                    || ReservaService::habitacionReservable((int)$h['id_estado_habitacion'])
            );
            $reservasActivas = (new Reserva())->getReservasActivas();
            $canales      = (new CanalOrigen())->getAll();

            $contentView = __DIR__ . '/../views/dashboard/editBooking.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $this->redirect('/dashboard/booking');
        }
    }

    public function editBooking(): void
    {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $datos = [
                'id_cliente'         => (int)($_POST['id_cliente'] ?? 0),
                'id_habitacion'      => (int)($_POST['id_habitacion'] ?? 0),
                'id_canal_origen'    => (int)($_POST['id_canal_origen'] ?? 0),
                'fecha_entrada'      => trim($_POST['fecha_entrada'] ?? ''),
                'fecha_salida'       => trim($_POST['fecha_salida'] ?? ''),
                'cantidad_huespedes' => (int)($_POST['cantidad_huespedes'] ?? 1),
                'observaciones'      => trim($_POST['observaciones'] ?? ''),
            ];

            $resultado = $this->reservaService->editar($id, $datos);

            $this->flash($resultado['message']);

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $this->recordarInputs($_POST);
            $this->flash($e->getMessage(), 'error');

            $this->redirect('/dashboard/booking/edit?id=' . ($_POST['id'] ?? 0));
        }
    }

    public function showBookingDetail(array $vars): void
    {
        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $flashMessage = $_SESSION['flash_message'] ?? '';
        unset($_SESSION['flash_message']);

        $flashStatus = $_SESSION['flash_status'] ?? '';
        unset($_SESSION['flash_status']);

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

            $estadoReserva = (int)$reserva['id_estado_reserva'];
            $upgradeOptions = [];
            if ($estadoReserva === Reserva::ESTADO_CONFIRMADA || $estadoReserva === Reserva::ESTADO_EN_CASA) {
                try {
                    $upgradeOptions = $this->reservaService->getOpcionesUpgrade((int)$id);
                } catch (Exception $e) {
                    $upgradeOptions = [];
                }
            }

            $contentView = __DIR__ . '/../views/dashboard/detailBooking.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $this->redirect('/dashboard/booking');
        }
    }

    public function cancelBooking(array $vars): void
    {
        try {
            $id = $_POST['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $resultado = $this->reservaService->cancelar((int)$id);

            $this->flash($resultado['message']);

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $this->redirect('/dashboard/booking');
        }
    }

    public function confirmBooking(array $vars): void
    {
        try {
            $id = $_POST['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $resultado = $this->reservaService->confirmar((int)$id);

            $this->flash($resultado['message']);

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $this->redirect('/dashboard/booking');
        }
    }

    public function checkInBooking(array $vars): void
    {
        try {
            $id = $_POST['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $resultado = $this->reservaService->checkIn((int)$id);

            $this->flash($resultado['message']);

            $this->redirect($this->destinoTrasAccion());
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $this->redirect($this->destinoTrasAccion());
        }
    }

    public function markNoShow(array $vars): void
    {
        try {
            $id = $_POST['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $resultado = $this->reservaService->marcarNoShow((int)$id);

            $this->flash($resultado['message']);

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $this->redirect('/dashboard/booking');
        }
    }

    public function finalizeBooking(array $vars): void
    {
        try {
            $id = $_POST['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $resultado = $this->reservaService->checkOut((int)$id);

            $this->flash($resultado['message']);

            $this->redirect($this->destinoTrasAccion());
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $this->redirect($this->destinoTrasAccion());
        }
    }

    public function earlyCheckoutBooking(array $vars): void
    {
        try {
            $id = $_POST['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $resultado = $this->reservaService->checkoutAnticipado((int)$id);

            $this->flash($resultado['message']);

            $this->redirect('/dashboard/booking');
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $this->redirect('/dashboard/booking');
        }
    }

    public function moveRoomBooking(array $vars): void
    {
        try {
            $idReserva  = (int)($_POST['id'] ?? 0);
            $idNuevaHab = (int)($_POST['nueva_habitacion'] ?? 0);

            if ($idReserva <= 0 || $idNuevaHab <= 0) {
                throw new Exception("Parámetros inválidos para el traslado.");
            }

            $resultado = $this->reservaService->moverHabitacion($idReserva, $idNuevaHab);

            $this->flash($resultado['message']);

            $this->redirect('/dashboard/booking/detail?id=' . $idReserva);
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $this->redirect('/dashboard/booking');
        }
    }

    public function extendBooking(array $vars): void
    {
        try {
            $idReserva   = (int)($_POST['id'] ?? 0);
            $nuevaSalida = trim($_POST['nueva_fecha_salida'] ?? '');

            if ($idReserva <= 0 || empty($nuevaSalida)) {
                throw new Exception("Parámetros inválidos para la extensión.");
            }

            $resultado = $this->reservaService->extenderEstadia($idReserva, $nuevaSalida);

            $this->flash($resultado['message']);

            $this->redirect('/dashboard/booking/detail?id=' . $idReserva);
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $this->redirect('/dashboard/booking');
        }
    }

    public function upgradeBooking(): void
    {
        try {
            $idReserva      = (int)($_POST['id'] ?? 0);
            $idNuevaHab     = (int)($_POST['nueva_habitacion'] ?? 0);

            if ($idReserva <= 0 || $idNuevaHab <= 0) {
                throw new Exception("Parámetros inválidos para el upgrade.");
            }

            $resultado = $this->reservaService->upgradeHabitacion($idReserva, $idNuevaHab);

            $this->flash($resultado['message']);

            $this->redirect('/dashboard/booking/detail?id=' . $idReserva);
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');

            $idReserva = (int)($_POST['id'] ?? 0);
            if ($idReserva > 0) {
                $this->redirect('/dashboard/booking/detail?id=' . $idReserva);
            } else {
                $this->redirect('/dashboard/booking');
            }
        }
    }

    /** Check-in y check-out se pueden hacer desde el inicio: volver ahí si el formulario lo pide. */
    private function destinoTrasAccion(): string
    {
        return ($_POST['volver'] ?? '') === 'inicio' ? '/dashboard' : '/dashboard/booking';
    }
}
