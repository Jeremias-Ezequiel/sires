<?php

namespace App\Controllers;

use Exception;
use App\Models\Reserva;
use App\Models\ResumenPago;
use App\Models\TransaccionPago;
use App\Models\MetodoPago;
use App\Services\PagoService;
use App\Helpers\UrlHelper;

class PaymentController
{
    private PagoService $pagoService;

    public function __construct()
    {
        $this->pagoService = new PagoService();
    }

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

            if ($resumen === null && (int)$reserva['id_estado_reserva'] !== Reserva::ESTADO_CANCELADA) {
                $resumen = $this->pagoService->generarResumen($reserva);

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

            $resultado = $this->pagoService->registrarPago(
                $idReserva,
                $idMetodoPago,
                $montoAbonado,
                (int)($_SESSION['user_id'] ?? 0)
            );

            $_SESSION['flash_message'] = $resultado['message'];
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
}