<?php

namespace App\Controllers;

use Exception;
use App\Models\Reserva;
use App\Models\Habitacion;
use App\Models\Clientes;
use App\Models\CanalOrigen;
use App\Models\ResumenPago;
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
                    && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_BLOQUEADA
                    && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_OCUPADA;
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

            $reserva = new Reserva();
            $reserva->setIdCliente($idCliente);
            $reserva->setIdHabitacion($idHabitacion);
            $reserva->setIdCanalOrigen($idCanal);
            $reserva->setFechaEntrada($fechaEntrada);
            $reserva->setFechaSalida($fechaSalida);
            $reserva->setCantidadHuespedes($cantHuespedes);
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
                        && (int)$h['id_estado_habitacion'] !== Habitacion::ESTADO_OCUPADA;
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
            $capacidadHabitacion = Habitacion::capacidadParaTipo((int)$habitacionRow['id_tipo_habitacion']);
            if ($cantHuespedes < 1 || $cantHuespedes > $capacidadHabitacion) {
                throw new Exception(
                    "La cantidad de huéspedes debe ser entre 1 y " . $capacidadHabitacion .
                    " según la capacidad de la habitación seleccionada."
                );
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
            $reserva->setCantidadHuespedes($cantHuespedes);
            $reserva->setObservaciones($observaciones ?: null);

            $success = $reservaModel->update($reserva);

            if (!$success) {
                throw new Exception("No se pudo actualizar la reserva. Solo se pueden editar reservas pendientes o confirmadas.");
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
            $id = $vars['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $reservaModel = new Reserva();
            $reservaData = $reservaModel->findById((int)$id);
            if ($reservaData === null) {
                throw new Exception("La reserva no existe.");
            }

            $estadoActual = (int)$reservaData['id_estado_reserva'];

            if ($estadoActual === Reserva::ESTADO_CANCELADA
                || $estadoActual === Reserva::ESTADO_FINALIZADA
                || $estadoActual === Reserva::ESTADO_EN_CASA) {
                throw new Exception("No se puede cancelar una reserva finalizada, en estadía o ya cancelada.");
            }

            if ($estadoActual === Reserva::ESTADO_PENDIENTE) {
                $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_CANCELADA, Reserva::ESTADO_PENDIENTE);
                if (!$success) {
                    throw new Exception("No se pudo cancelar la reserva.");
                }
            } else {
                $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_CANCELADA, Reserva::ESTADO_CONFIRMADA);
                if (!$success) {
                    throw new Exception("No se pudo cancelar la reserva. Solo se pueden cancelar reservas pendientes o confirmadas.");
                }

                $resumen = (new ResumenPago())->getByReserva((int)$id);
                if ($resumen !== null && $resumen->getMontoPagado() > 0) {
                    $resumen->reembolsarPorReserva((int)$id);
                    $_SESSION['flash_message'] = "Reserva cancelada y pago reembolsado exitosamente.";
                    $_SESSION['flash_status']  = "success";
                }
            }

            (new Habitacion())->cambiarEstado((int)$reservaData['id_habitacion'], Habitacion::ESTADO_DISPONIBLE);

            if (empty($_SESSION['flash_message'])) {
                $_SESSION['flash_message'] = "Reserva cancelada exitosamente.";
                $_SESSION['flash_status']  = "success";
            }

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
            $reservaData = $reservaModel->findById((int)$id);
            if ($reservaData === null) {
                throw new Exception("La reserva no existe.");
            }

            if ((int)$reservaData['id_estado_reserva'] !== Reserva::ESTADO_PENDIENTE) {
                throw new Exception("Solo se pueden confirmar reservas pendientes.");
            }

            $resumen = (new ResumenPago())->getByReserva((int)$id);

            if ($resumen === null || $resumen->getMontoPagado() <= 0) {
                $_SESSION['flash_message'] = "Debe realizar al menos un pago (depósito) para confirmar la reserva.";
                $_SESSION['flash_status']  = "warning";
                header('Location: ' . UrlHelper::to('/dashboard/payments/detail?id=' . $id));
                exit;
            }

            $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_CONFIRMADA, Reserva::ESTADO_PENDIENTE);

            if (!$success) {
                throw new Exception("No se pudo confirmar la reserva.");
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

            $reservaModel = new Reserva();
            $reservaData = $reservaModel->findById((int)$id);
            if ($reservaData === null) {
                throw new Exception("La reserva no existe.");
            }

            if ((int)$reservaData['id_estado_reserva'] !== Reserva::ESTADO_CONFIRMADA) {
                throw new Exception("Solo se puede hacer check-in de reservas confirmadas.");
            }

            $resumen = (new ResumenPago())->getByReserva((int)$id);
            if ($resumen === null || $resumen->getMontoPagado() <= 0) {
                throw new Exception("No se puede realizar el check-in. Debe haber al menos un depósito pagado.");
            }

            $habitacionModel = new Habitacion();
            $roomData = $habitacionModel->findById((int)$reservaData['id_habitacion']);
            if (!$roomData) {
                throw new Exception("La habitación asignada no existe.");
            }
            if ((int)$roomData['id_estado_habitacion'] === Habitacion::ESTADO_OCUPADA) {
                throw new Exception("El check-in ya fue realizado. La habitación ya se encuentra ocupada.");
            }

            $habitacionModel->cambiarEstado((int)$reservaData['id_habitacion'], Habitacion::ESTADO_OCUPADA);

            $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_EN_CASA, Reserva::ESTADO_CONFIRMADA);
            if (!$success) {
                $habitacionModel->cambiarEstado((int)$reservaData['id_habitacion'], Habitacion::ESTADO_DISPONIBLE);
                throw new Exception("Error al actualizar el estado de la reserva.");
            }

            $msg = "Check-in realizado. La habitación ha sido ocupada.";
            if ($resumen->getIdEstadoPago() !== ResumenPago::ESTADO_PAGADO_TOTAL) {
                $saldo = $resumen->getSaldoPendiente();
                $msg .= " Queda un saldo pendiente de $" . number_format($saldo, 2, ',', '.') . ".";
            }

            $_SESSION['flash_message'] = $msg;
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

            $reservaModel = new Reserva();
            $reservaData = $reservaModel->findById((int)$id);
            if ($reservaData === null) {
                throw new Exception("La reserva no existe.");
            }

            if ((int)$reservaData['id_estado_reserva'] !== Reserva::ESTADO_CONFIRMADA) {
                throw new Exception("Solo se puede marcar como No-Show reservas confirmadas que no se presentaron.");
            }

            $resumen = (new ResumenPago())->getByReserva((int)$id);
            if ($resumen !== null && $resumen->getMontoPagado() > 0) {
                $resumen->reembolsarPorReserva((int)$id);
            }

            $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_NO_SHOW, Reserva::ESTADO_CONFIRMADA);
            if (!$success) {
                throw new Exception("No se pudo marcar la reserva como No-Show.");
            }

            (new Habitacion())->cambiarEstado((int)$reservaData['id_habitacion'], Habitacion::ESTADO_DISPONIBLE);

            $msg = "Reserva marcada como No-Show. La habitación ha sido liberada.";
            if ($resumen !== null && $resumen->getMontoPagado() > 0) {
                $msg .= " El pago fue reembolsado.";
            }

            $_SESSION['flash_message'] = $msg;
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
            $id = $vars['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $reservaModel = new Reserva();
            $reservaData = $reservaModel->findById((int)$id);
            if ($reservaData === null) {
                throw new Exception("La reserva no existe.");
            }

            $estadoActual = (int)$reservaData['id_estado_reserva'];
            if ($estadoActual !== Reserva::ESTADO_CONFIRMADA && $estadoActual !== Reserva::ESTADO_EN_CASA) {
                throw new Exception("No se puede finalizar una reserva que no está confirmada o en estadía.");
            }

            $resumen = (new ResumenPago())->getByReserva((int)$id);
            if ($resumen === null || $resumen->getIdEstadoPago() !== ResumenPago::ESTADO_PAGADO_TOTAL) {
                throw new Exception("No se puede finalizar la reserva hasta que el pago esté realizado en su totalidad.");
            }

            $success = $reservaModel->cambiarEstado((int)$id, Reserva::ESTADO_FINALIZADA, $estadoActual);

            if (!$success) {
                throw new Exception("No se pudo finalizar la reserva.");
            }

            (new Habitacion())->cambiarEstado((int)$reservaData['id_habitacion'], Habitacion::ESTADO_DISPONIBLE);

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
