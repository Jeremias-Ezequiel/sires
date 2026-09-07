<?php

namespace App\Controllers;

use Exception;
use App\Models\Reserva;
use App\Models\ResumenPago;
use App\Models\TransaccionPago;
use App\Models\MetodoPago;
use App\Helpers\UrlHelper;

class PaymentController
{
    public function showPayments(array $vars): void
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

        $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($currentPage < 1) { $currentPage = 1; }

        $limit = 10;
        $offset = ($currentPage - 1) * $limit;

        $search       = $vars['search'] ?? "";
        $estadoPago   = $vars['estado_pago_filter'] ?? "";

        $hasSearch = !empty($vars['search']);
        $hasEstado = isset($vars['estado_pago_filter']) && $vars['estado_pago_filter'] !== '';

        $resumenModel = new ResumenPago();

        $totalPagos = $resumenModel->countPagos($search, $estadoPago);
        $totalPages = (int)ceil($totalPagos / $limit);
        if ($totalPages < 1) { $totalPages = 1; }
        if ($currentPage > $totalPages) { $currentPage = $totalPages; $offset = ($currentPage - 1) * $limit; }

        $pagos      = $resumenModel->listPagos($search, $estadoPago, $limit, $offset);
        $estadosPago = $resumenModel->getEstadosPago();

        $contentView = __DIR__ . '/../views/dashboard/pagos.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function showPaymentDetail(array $vars): void
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

        try {
            $id = $vars['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de reserva inválido.");
            }

            $reserva = (new Reserva())->findById((int)$id);
            if (!$reserva) {
                throw new Exception("La reserva no existe.");
            }

            $resumenModel = new ResumenPago();
            $resumen = $resumenModel->getByReserva((int)$id);

            // Si la reserva es válida para cobrar y aún no tiene resumen, lo generamos
            if ($resumen === null && (int)$reserva['id_estado_reserva'] !== Reserva::ESTADO_CANCELADA) {
                $resumen = $this->generarResumen($reserva);

                if ($resumen !== null) {
                    $_SESSION['flash_message'] = "Se generó el resumen de pago de la reserva automáticamente.";
                    $_SESSION['flash_status']  = "success";
                    $flashMessage = $_SESSION['flash_message'];
                    $flashStatus  = $_SESSION['flash_status'];
                    unset($_SESSION['flash_message'], $_SESSION['flash_status']);
                }
            }

            $transacciones = [];
            if ($resumen !== null) {
                $transacciones = (new TransaccionPago())->getByResumenPago($resumen->getId());
            }

            $metodos = (new MetodoPago())->getAll();

            $contentView = __DIR__ . '/../views/dashboard/detailPago.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/payments'));
            exit;
        }
    }

    public function addPayment(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $idReserva     = (int)($_POST['id_reserva'] ?? 0);
            $idMetodoPago  = (int)($_POST['id_metodo_pago'] ?? 0);
            $montoAbonado  = (float)($_POST['monto_abonado'] ?? 0);

            if ($idReserva <= 0) {
                throw new Exception("Ocurrió un error al seleccionar la reserva.");
            }
            if ($idMetodoPago <= 0) {
                throw new Exception("Debe seleccionar un método de pago.");
            }
            if ($montoAbonado <= 0) {
                throw new Exception("El monto a abonar debe ser mayor a 0.");
            }

            $reserva = (new Reserva())->findById($idReserva);
            if (!$reserva) {
                throw new Exception("La reserva no existe.");
            }

            if ((int)$reserva['id_estado_reserva'] === Reserva::ESTADO_CANCELADA) {
                throw new Exception("No se pueden registrar pagos sobre una reserva cancelada.");
            }

            $resumenModel = new ResumenPago();
            $resumen = $resumenModel->getByReserva($idReserva);

            if ($resumen === null) {
                $resumen = $this->generarResumen($reserva);
            }

            if ($resumen === null) {
                throw new Exception("No se pudo generar el resumen de pago de la reserva.");
            }

            if ($resumen->getSaldoPendiente() <= 0) {
                throw new Exception("La reserva ya se encuentra totalmente pagada.");
            }

            if ($montoAbonado > $resumen->getSaldoPendiente()) {
                throw new Exception("El monto ingresado supera el saldo pendiente de $" . number_format($resumen->getSaldoPendiente(), 2, ',', '.') . ".");
            }

            $transaccion = new TransaccionPago();
            $transaccion->setIdResumenPago($resumen->getId());
            $transaccion->setIdMetodoPago($idMetodoPago);
            $transaccion->setMontoAbonado($montoAbonado);
            $transaccion->setRegistradoPor((int)($_SESSION['user_id'] ?? 0));

            $success = (new TransaccionPago())->save($transaccion);
            if (!$success) {
                throw new Exception("No se pudo registrar la transacción de pago.");
            }

            $nuevoMontoPagado = $resumen->getMontoPagado() + $montoAbonado;
            $nuevoSaldo       = $resumen->getSaldoPendiente() - $montoAbonado;

            if ($nuevoSaldo <= 0) {
                $nuevoEstado = ResumenPago::ESTADO_PAGADO_TOTAL;
            } elseif ($nuevoMontoPagado > 0) {
                $nuevoEstado = ResumenPago::ESTADO_PAGO_PARCIAL;
            } else {
                $nuevoEstado = ResumenPago::ESTADO_PENDIENTE;
            }

            $resumen->setIdEstadoPago($nuevoEstado);
            $resumen->setMontoPagado($nuevoMontoPagado);
            $resumen->setSaldoPendiente($nuevoSaldo);

            $updated = $resumenModel->update($resumen);
            if (!$updated) {
                throw new Exception("No se pudo actualizar el resumen de pago.");
            }

            $_SESSION['flash_message'] = "Pago registrado exitosamente por $" . number_format($montoAbonado, 2, ',', '.') . ".";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/payments/detail?id=' . $idReserva));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/payments/detail?id=' . (int)($_POST['id_reserva'] ?? 0)));
            exit;
        }
    }

    /**
     * Genera el Resumen_Pago de una reserva calculando el total por noches
     * usando el precio base de la habitación. Retorna null si no es calculable.
     */
    private function generarResumen(array $reserva): ?ResumenPago
    {
        try {
            if ((int)$reserva['id_estado_reserva'] === Reserva::ESTADO_CANCELADA) {
                return null;
            }

            $entrada = new \DateTime($reserva['fecha_entrada']);
            $salida  = new \DateTime($reserva['fecha_salida']);

            if ($salida <= $entrada) {
                throw new Exception("La reserva no tiene fechas válidas para calcular el total.");
            }

            $noches = $entrada->diff($salida)->days;
            $total  = (float)$reserva['precio_noche_base'] * $noches;

            if ($total <= 0) {
                throw new Exception("No se pudo calcular un total válido para la reserva.");
            }

            $resumen = new ResumenPago();
            $resumen->setIdReserva((int)$reserva['id']);
            $resumen->setIdEstadoPago(ResumenPago::ESTADO_PENDIENTE);
            $resumen->setTotal($total);
            $resumen->setMontoPagado(0.0);
            $resumen->setSaldoPendiente($total);

            $saved = (new ResumenPago())->save($resumen);
            if (!$saved) {
                throw new Exception("No se pudo crear el resumen de pago.");
            }

            return (new ResumenPago())->getByReserva((int)$reserva['id']);
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";
            return null;
        }
    }
}