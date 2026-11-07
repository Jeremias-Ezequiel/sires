<?php

declare(strict_types=1);

namespace App\Controllers;

use Exception;
use App\Models\Reserva;
use App\Models\Habitacion;
use App\Models\Clientes;
use App\Models\CanalOrigen;
use App\Services\ReservaService;
use App\Dominio\Planning;

class BookingController extends Controller
{
    private const ID_INVALIDO = 'ID de reserva inválido.';

    private ReservaService $reservaService;

    public function __construct()
    {
        $this->reservaService = new ReservaService();
    }

    public function showBooking(array $vars): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

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

    public function showNewBookingForm(array $vars = []): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $old = $this->tomarInputs();

        // Desde el planning: habitación y fecha de entrada precargadas (salida = entrada + 1)
        if ($old === [] && isset($vars['id_habitacion'], $vars['fecha_entrada'])) {
            $hoy = date('Y-m-d');
            $entrada = Planning::normalizarDesde($vars['fecha_entrada'], $hoy);
            if (filter_var($vars['id_habitacion'], FILTER_VALIDATE_INT) !== false && $entrada >= $hoy) {
                $old = [
                    'id_habitacion' => (int)$vars['id_habitacion'],
                    'fecha_entrada' => $entrada,
                    'fecha_salida'  => Planning::desplazar($entrada, 1),
                ];
            }
        }

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
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $old = $this->tomarInputs();

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        try {
            $id = $this->idDe('id', self::ID_INVALIDO, $vars);

            $reservaModel = new Reserva();
            $reserva = $reservaModel->findById($id);

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
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        try {
            $id = $this->idDe('id', self::ID_INVALIDO, $vars);

            $reserva = (new Reserva())->findById($id);
            if (!$reserva) {
                throw new Exception("La reserva no existe.");
            }

            $estadoReserva = (int)$reserva['id_estado_reserva'];
            $upgradeOptions = [];
            if ($estadoReserva === Reserva::ESTADO_CONFIRMADA || $estadoReserva === Reserva::ESTADO_EN_CASA) {
                try {
                    $upgradeOptions = $this->reservaService->getOpcionesUpgrade($id);
                } catch (Exception $e) {
                    $upgradeOptions = [];
                }
            }

            // Historial de auditoría: solo administrador y gerente
            $historial = null;
            if (\App\Middleware\AuthMiddleware::hasRole([\App\Models\Rol::ADMINISTRADOR, \App\Models\Rol::GERENTE])) {
                try {
                    $historial = (new \App\Services\AuditoriaService())->historial('reserva', $id);
                } catch (Exception $e) {
                    error_log("[SIRES AUDITORIA] " . $e->getMessage());
                }
            }

            // Comprobantes PDF emitidos para la reserva
            $comprobantes = [];
            try {
                $comprobantes = (new \App\Services\ComprobanteService())->porReserva($id);
            } catch (\Throwable $e) {
                error_log("[SIRES COMPROBANTE] " . $e->getMessage());
            }

            // Mails al huésped de esta reserva (Cola_Mails)
            $mails = [];
            try {
                $mails = (new \App\Models\ColaMail())->porReserva($id);
            } catch (\Throwable $e) {
                error_log("[SIRES MAIL] " . $e->getMessage());
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
        $this->ejecutarAccion(
            fn() => $this->reservaService->cancelar($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/booking'
        );
    }

    public function confirmBooking(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->reservaService->confirmar($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/booking'
        );
    }

    public function checkInBooking(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->reservaService->checkIn($this->idDe('id', self::ID_INVALIDO)),
            $this->destinoTrasAccion()
        );
    }

    public function markNoShow(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->reservaService->marcarNoShow($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/booking'
        );
    }

    public function finalizeBooking(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->reservaService->checkOut($this->idDe('id', self::ID_INVALIDO)),
            $this->destinoTrasAccion()
        );
    }

    public function earlyCheckoutBooking(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->reservaService->checkoutAnticipado($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/booking'
        );
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
