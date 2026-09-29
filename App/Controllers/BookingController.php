<?php

namespace App\Controllers;

use Exception;
use App\Models\Reserva;
use App\Models\Habitacion;
use App\Models\Clientes;
use App\Models\CanalOrigen;
use App\Models\ResumenPago;
use App\Models\Auditoria;
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
        $habitaciones = array_filter(
            (new Habitacion())->getAllWithFilters(null, null, null, null),
            function (array $h): bool {
                return (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_MANTENIMIENTO
                    && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_BLOQUEADA;
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
            csrf_check();

            $idCliente      = (int)($_POST['id_cliente'] ?? 0);
            $idHabitacion   = (int)($_POST['id_habitacion'] ?? 0);
            $idCanal        = (int)($_POST['id_canal_origen'] ?? 0);
            $fechaEntrada   = trim($_POST['fecha_entrada'] ?? '');
            $fechaSalida    = trim($_POST['fecha_salida'] ?? '');
            $cantHuespedes  = (int)($_POST['cantidad_huespedes'] ?? 1);
            $cantNinos      = (int)($_POST['ninos'] ?? 0);
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

            $habitacionRow = (new Habitacion())->findById($idHabitacion);
            if (!$habitacionRow) {
                throw new Exception("La habitación seleccionada no existe.");
            }
            if ((int)$habitacionRow['id_estado_habitacion'] === Habitacion::ESTADO_MANTENIMIENTO
                || (int)$habitacionRow['id_estado_habitacion'] === Habitacion::ESTADO_BLOQUEADA) {
                throw new Exception("La habitación seleccionada no está disponible.");
            }
            $capacidadHabitacion = Habitacion::capacidadParaTipo((int)$habitacionRow['id_tipo_habitacion']);
            if ($cantHuespedes < 1 || $cantHuespedes > $capacidadHabitacion) {
                throw new Exception(
                    "La cantidad de huéspedes debe ser entre 1 y " . $capacidadHabitacion .
                    " según la capacidad de la habitación seleccionada."
                );
            }
            // Los dos casos van separados porque son errores distintos: con un
            // solo mensaje, un -3 recibia un cartel que hablaba de "no puede ser
            // mayor", que no es lo que paso.
            if ($cantNinos < 0) {
                throw new Exception("La cantidad de niños no puede ser negativa.");
            }
            if ($cantNinos > $cantHuespedes) {
                throw new Exception(
                    "La cantidad de niños no puede ser mayor que la cantidad total de huéspedes (" .
                    $cantHuespedes . ")."
                );
            }

            // Solución 2: Asegurar que cantidad_huespedes sea al menos la suma de adultos + niños
            // Si el formulario envía cantidad_huespedes = 1 pero hay niños, ajustar automáticamente
            $cantidadMinima = $cantNinos + 1; // Al menos 1 adulto + los niños
            if ($cantHuespedes < $cantidadMinima) {
                $cantHuespedes = $cantidadMinima;
            }

            $reserva = new Reserva();
            $reserva->setIdCliente($idCliente);
            $reserva->setIdHabitacion($idHabitacion);
            $reserva->setIdCanalOrigen($idCanal);
            $reserva->setFechaEntrada($fechaEntrada);
            $reserva->setFechaSalida($fechaSalida);
            $reserva->setDistribucionHuespedes($cantHuespedes - $cantNinos, $cantNinos);
            $reserva->setObservaciones($observaciones ?: null);
            $reserva->setCreadoPor($_SESSION['user_id'] ?? 0);

            $reservaModel = new Reserva();
            $db = $reservaModel->getConnection();
            $db->beginTransaction();

            try {
                if ($reservaModel->existeSolapamiento($idHabitacion, $fechaEntrada, $fechaSalida)) {
                    throw new Exception("La habitación ya tiene una reserva pendiente o confirmada para ese rango de fechas.");
                }

                $success = $reservaModel->save($reserva);
                if (!$success) {
                    throw new Exception("No se pudo registrar la reserva. Verifique los datos ingresados.");
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
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

    /**
     * Lee el id de la reserva de una peticion que cambia estado.
     *
     * Estas cuatro acciones (check-in, checkout, confirmar, anular) son POST y
     * no GET. Con GET, un <img src=".../checkin?id=5"> pegado en cualquier
     * pagina ajena alcanza para disparar el check-in de una reserva: el
     * navegador pide la URL solo, sin que el usuario haga nada. Por eso el id
     * viene del cuerpo del POST y no de la query.
     *
     * Se acepta tambien $vars para no romper si algun llamado legitimo sigue
     * pasando la ruta.
     */
    private function idDeReserva(array $vars): int
    {
        $id = $_POST['id'] ?? $vars['id'] ?? null;

        if ($id === null || $id === '' || filter_var($id, FILTER_VALIDATE_INT) === false) {
            throw new Exception("ID de reserva inválido.");
        }

        return (int)$id;
    }

    /**
     * Numero visible de la habitacion para los mensajes de auditoria.
     *
     * Sin esto la bitacora mezcla "#201" (numero) con "#3" (id) segun la accion,
     * y eso hace imposible seguir una habitacion en la bitacora.
     * Si la habitacion no se puede leer, cae al id en vez de romper la operacion.
     */
    private function numeroDeHabitacion(Habitacion $roomModel, int $idHabitacion): string
    {
        $habitacion = $roomModel->findById($idHabitacion);
        if ($habitacion === null || !isset($habitacion['numero'])) {
            return (string)$idHabitacion;
        }

        return (string)$habitacion['numero'];
    }

    public function checkinBooking(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $id = $this->idDeReserva($vars);

            $reserva = (new Reserva())->findById((int)$id);
            if ($reserva === null) {
                throw new Exception("La reserva no existe.");
            }

            if ((int)$reserva['id_estado_reserva'] !== Reserva::ESTADO_CONFIRMADA) {
                throw new Exception("Solo se puede hacer check-in de reservas confirmadas.");
            }

            // Verificar que el pago esté completo antes del check-in
            $resumen = (new ResumenPago())->getByReserva((int)$id);
            if ($resumen === null) {
                throw new Exception("La reserva no tiene un resumen de pago asociado. Contacte al administrador.");
            }
            if ($resumen->getSaldoPendiente() > 0) {
                throw new Exception(
                    "No se puede realizar el check-in: la reserva tiene un saldo pendiente de $"
                    . number_format($resumen->getSaldoPendiente(), 2, ',', '.')
                    . ". Por favor, regularice el pago antes del check-in."
                );
            }

            $idHabitacion = (int)$reserva['id_habitacion'];
            $habitacion = (new Habitacion())->findById($idHabitacion);
            if ($habitacion === null) {
                throw new Exception("La habitación asociada no existe.");
            }

            if ((int)$habitacion['id_estado_habitacion'] === Habitacion::ESTADO_MANTENIMIENTO
                || (int)$habitacion['id_estado_habitacion'] === Habitacion::ESTADO_BLOQUEADA) {
                throw new Exception("La habitación se encuentra en mantenimiento o bloqueada; no es posible hacer check-in.");
            }

            if ((int)$habitacion['id_estado_habitacion'] === Habitacion::ESTADO_OCUPADA) {
                $_SESSION['flash_message'] = "La reserva ya posee check-in registrado (la habitación se encuentra ocupada).";
                $_SESSION['flash_status']  = "success";
                header('Location: ' . UrlHelper::to('/dashboard/booking'));
                exit;
            }

            $roomModel = new Habitacion();
            $roomModel->cambiarEstado($idHabitacion, Habitacion::ESTADO_OCUPADA);

            (new Auditoria())->registrar(
                Auditoria::CHECKIN,
                'reservas',
                (int)$id,
                "Check-in de la reserva #{$id} en la habitación #{$habitacion['numero']}",
                ['estado_reserva' => (int)$reserva['id_estado_reserva'], 'estado_habitacion' => (int)$habitacion['id_estado_habitacion']],
                ['estado_reserva' => (int)$reserva['id_estado_reserva'], 'estado_habitacion' => Habitacion::ESTADO_OCUPADA]
            );

            $_SESSION['flash_message'] = "Check-in realizado: la habitación #{$habitacion['numero']} fue marcada como ocupada.";
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
            $habitaciones = array_filter(
                (new Habitacion())->getAllWithFilters(null, null, null, null),
                function (array $h): bool {
                    return (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_MANTENIMIENTO
                        && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_BLOQUEADA;
                }
            );
            $reservasActivas = (new Reserva())->getReservasActivas();
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
            csrf_check();

            $id             = (int)($_POST['id'] ?? 0);
            $idCliente      = (int)($_POST['id_cliente'] ?? 0);
            $idHabitacion   = (int)($_POST['id_habitacion'] ?? 0);
            $idCanal        = (int)($_POST['id_canal_origen'] ?? 0);
            $fechaEntrada   = trim($_POST['fecha_entrada'] ?? '');
            $fechaSalida    = trim($_POST['fecha_salida'] ?? '');
            $cantHuespedes  = (int)($_POST['cantidad_huespedes'] ?? 1);
            $cantNinos      = (int)($_POST['ninos'] ?? 0);
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
            $capacidadHabitacion = Habitacion::capacidadParaTipo((int)$habitacionRow['id_tipo_habitacion']);
            if ($cantHuespedes < 1 || $cantHuespedes > $capacidadHabitacion) {
                throw new Exception(
                    "La cantidad de huéspedes debe ser entre 1 y " . $capacidadHabitacion .
                    " según la capacidad de la habitación seleccionada."
                );
            }
            // Los dos casos van separados porque son errores distintos: con un
            // solo mensaje, un -3 recibia un cartel que hablaba de "no puede ser
            // mayor", que no es lo que paso.
            if ($cantNinos < 0) {
                throw new Exception("La cantidad de niños no puede ser negativa.");
            }
            if ($cantNinos > $cantHuespedes) {
                throw new Exception(
                    "La cantidad de niños no puede ser mayor que la cantidad total de huéspedes (" .
                    $cantHuespedes . ")."
                );
            }

            $reservaModel = new Reserva();
            $reservaActual = $reservaModel->findById($id);
            if (!$reservaActual) {
                throw new Exception("La reserva no existe.");
            }

            $db = $reservaModel->getConnection();
            $db->beginTransaction();

            try {
                if ((int)$reservaActual['id_habitacion'] !== $idHabitacion
                    && ((int)$habitacionRow['id_estado_habitacion'] === Habitacion::ESTADO_MANTENIMIENTO
                        || (int)$habitacionRow['id_estado_habitacion'] === Habitacion::ESTADO_BLOQUEADA)) {
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
                $reserva->setDistribucionHuespedes($cantHuespedes - $cantNinos, $cantNinos);
                $reserva->setObservaciones($observaciones ?: null);

                $success = $reservaModel->update($reserva);

                if (!$success) {
                    throw new Exception("No se pudo actualizar la reserva. Solo se pueden editar reservas pendientes o confirmadas.");
                }

                // Liberar/ocupar habitaciones solo si el check-in ya se había realizado
                $idHabitacionAnterior = (int)$reservaActual['id_habitacion'];
                $estadoHabitacionAnterior = Habitacion::estadoEn($db, $idHabitacionAnterior);

                if ($idHabitacionAnterior !== $idHabitacion && $estadoHabitacionAnterior === Habitacion::ESTADO_OCUPADA) {
                    Habitacion::cambiarEstadoEn($db, $idHabitacionAnterior, Habitacion::ESTADO_DISPONIBLE);

                    $estadoHabitacionNueva = Habitacion::estadoEn($db, $idHabitacion);
                    if ($estadoHabitacionNueva === Habitacion::ESTADO_DISPONIBLE) {
                        Habitacion::cambiarEstadoEn($db, $idHabitacion, Habitacion::ESTADO_OCUPADA);
                    }
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

            $reservaData = $reservaModel->findById($id);
            if ($reservaData !== null) {
                $resumenResultado = (new ResumenPago())->recalcular($reservaData);
                if ($resumenResultado) {
                    $_SESSION['flash_message'] = "Reserva actualizada y resumen de pago recalculado exitosamente.";
                    $_SESSION['flash_status']  = "success";
                }
            }

            if (empty($_SESSION['flash_message'])) {
                $_SESSION['flash_message'] = "Reserva actualizada exitosamente.";
                $_SESSION['flash_status']  = "success";
            }

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
            csrf_check();

            $id = $this->idDeReserva($vars);

            $reservaModel = new Reserva();
            $reservaData = $reservaModel->findById((int)$id);
            if ($reservaData === null) {
                throw new Exception("La reserva no existe.");
            }

            $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_CANCELADA, Reserva::ESTADO_PENDIENTE);

            if (!$success) {
                $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_CANCELADA, Reserva::ESTADO_CONFIRMADA);
            }

            if (!$success) {
                throw new Exception("No se pudo cancelar la reserva. Solo se pueden cancelar reservas pendientes o confirmadas.");
            }

            $roomModel = new Habitacion();
            $idHabitacion = (int)$reservaData['id_habitacion'];

            if ($roomModel->estadoEn($reservaModel->getConnection(), $idHabitacion) === Habitacion::ESTADO_OCUPADA) {
                $roomModel->cambiarEstado($idHabitacion, Habitacion::ESTADO_DISPONIBLE);
            }

            (new Auditoria())->registrar(
                Auditoria::ANULAR,
                'reservas',
                (int)$id,
                "Reserva #{$id} cancelada (habitacion #{$this->numeroDeHabitacion($roomModel, $idHabitacion)})",
                ['estado_reserva' => (int)$reservaData['id_estado_reserva']],
                ['estado_reserva' => Reserva::ESTADO_CANCELADA]
            );

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
            csrf_check();

            $id = $this->idDeReserva($vars);

            $reservaModel = new Reserva();
            $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_CONFIRMADA, Reserva::ESTADO_PENDIENTE);

            if (!$success) {
                throw new Exception("No se pudo confirmar la reserva. Solo se pueden confirmar reservas pendientes.");
            }

            $reservaData = (new Reserva())->findById((int)$id);
            if ($reservaData !== null) {
                PaymentController::generarResumen($reservaData);
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

    public function finalizeBooking(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $id = $this->idDeReserva($vars);

            $reservaModel = new Reserva();
            $reservaData = $reservaModel->findById((int)$id);
            if ($reservaData === null) {
                throw new Exception("La reserva no existe.");
            }

            $resumen = (new ResumenPago())->getByReserva((int)$id);
            if ($resumen === null || $resumen->getIdEstadoPago() !== ResumenPago::ESTADO_PAGADO_TOTAL) {
                throw new Exception("No se puede finalizar la reserva hasta que el pago esté realizado en su totalidad.");
            }

            $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_FINALIZADA, Reserva::ESTADO_PENDIENTE);

            if (!$success) {
                $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_FINALIZADA, Reserva::ESTADO_CONFIRMADA);
            }

            if (!$success) {
                throw new Exception("No se pudo finalizar la reserva. Solo se pueden finalizar reservas pendientes o confirmadas.");
            }

            $roomModel = new Habitacion();
            $idHabitacion = (int)$reservaData['id_habitacion'];

            if ($roomModel->estadoEn($reservaModel->getConnection(), $idHabitacion) === Habitacion::ESTADO_OCUPADA) {
                $roomModel->cambiarEstado($idHabitacion, Habitacion::ESTADO_DISPONIBLE);
            }

            (new Auditoria())->registrar(
                Auditoria::CHECKOUT,
                'reservas',
                (int)$id,
                "Check-out de la reserva #{$id} (habitacion #{$this->numeroDeHabitacion($roomModel, $idHabitacion)})",
                ['estado_reserva' => (int)$reservaData['id_estado_reserva']],
                ['estado_reserva' => Reserva::ESTADO_FINALIZADA]
            );

            $_SESSION['flash_message'] = "Reserva finalizada exitosamente.";
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
