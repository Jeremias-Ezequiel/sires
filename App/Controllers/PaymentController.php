<?php

declare(strict_types=1);

namespace App\Controllers;

use Exception;
use App\Models\Reserva;
use App\Models\ResumenPago;
use App\Models\TransaccionPago;
use App\Models\MetodoPago;
use App\Services\PagoService;

class PaymentController extends Controller
{
    private const ID_INVALIDO = 'ID de reserva inválido.';

    private PagoService $pagoService;

    public function __construct()
    {
        $this->pagoService = new PagoService();
    }

    public function showPayments(array $vars): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

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
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $old = $this->tomarInputs();

        try {
            $id = $this->idDe('id', self::ID_INVALIDO, $vars);

            $reserva = (new Reserva())->findById($id);
            if (!$reserva) {
                throw new Exception("La reserva no existe.");
            }

            $resumenModel = new ResumenPago();
            $resumen = $resumenModel->getByReserva($id);

            if ($resumen === null && (int)$reserva['id_estado_reserva'] !== Reserva::ESTADO_CANCELADA) {
                $resumen = $this->pagoService->generarResumen($reserva);

                if ($resumen !== null) {
                    $flashMessage = "Se generó el resumen de pago de la reserva automáticamente.";
                    $flashStatus  = "success";
                }
            }

            $transacciones = [];
            if ($resumen !== null) {
                $transacciones = (new TransaccionPago())->getByResumenPago($resumen->getId());
            }

            $metodos = (new MetodoPago())->getAll();

            // Recibos emitidos, por transacción (Comprobantes, SIRES 1.7.0)
            $comprobantesPorTx = [];
            try {
                foreach ((new \App\Services\ComprobanteService())->porReserva($id) as $c) {
                    if ($c['id_transaccion'] !== null) {
                        $comprobantesPorTx[(int)$c['id_transaccion']] = $c;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[SIRES COMPROBANTE] ' . $e->getMessage());
            }

            $contentView = __DIR__ . '/../views/dashboard/detailPago.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');
            $this->redirect('/dashboard/payments');
        }
    }

    public function addPayment(): void
    {
        $idReserva = (int)($_POST['id_reserva'] ?? 0);
        $this->ejecutarAccion(function () use ($idReserva): array {
            csrf_check();
            return $this->pagoService->registrarPago(
                $idReserva,
                (int)($_POST['id_metodo_pago'] ?? 0),
                (float)($_POST['monto_abonado'] ?? 0),
                (int)($_SESSION['user_id'] ?? 0)
            );
        }, '/dashboard/payments/detail?id=' . $idReserva, null, true);
    }

    public function recalculateReservation(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->pagoService->recalcularManual($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/payments/detail?id=' . (int)($_POST['id'] ?? 0)
        );
    }

    public function refundPayment(): void
    {
        // Las validaciones (estado A Reembolsar, monto > 0) las hace PagoService::procesarReembolso
        $this->ejecutarAccion(function (): array {
            csrf_check();
            return $this->pagoService->procesarReembolso($this->idDe('id_reserva', self::ID_INVALIDO));
        }, '/dashboard/payments/detail?id=' . (int)($_POST['id_reserva'] ?? 0));
    }
}
