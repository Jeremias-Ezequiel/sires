<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Dominio\Comprobante;
use App\Services\ComprobanteService;
use Throwable;

/**
 * Comprobantes PDF: descarga de uno emitido, emisión del recibo de una transacción
 * anterior a 1.7.0 y emisión de un resumen nuevo de la reserva.
 */
class ComprobanteController extends Controller
{
    public function descargar(array $vars): void
    {
        $id = filter_var($vars['id'] ?? '', FILTER_VALIDATE_INT);
        $servicio = new ComprobanteService();
        $comprobante = $id === false ? null : $servicio->porId((int)$id);
        if ($comprobante === null) {
            $this->flash('El comprobante no existe.', 'error');
            $this->redirect('/dashboard/payments');
        }

        try {
            $pdf = $servicio->pdf($comprobante);
        } catch (Throwable $e) {
            error_log('[SIRES COMPROBANTE] ' . $e->getMessage());
            $this->flash('No se pudo generar el PDF del comprobante.', 'error');
            $this->redirect('/dashboard/booking/detail?id=' . (int)$comprobante['id_reserva']);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $pdf['nombre'] . '"');
        header('Content-Length: ' . strlen($pdf['contenido']));
        header('Cache-Control: private, no-store');
        echo $pdf['contenido'];
        exit;
    }

    /** Recibo de una transacción que todavía no lo tiene (registrada antes de 1.7.0). */
    public function emitirDeTransaccion(array $vars): void
    {
        $idTx = filter_var($_POST['id_transaccion'] ?? '', FILTER_VALIDATE_INT);
        $idReserva = (int)($_POST['id_reserva'] ?? 0);
        try {
            if ($idTx === false) {
                throw new \Exception('Transacción inválida.');
            }
            $c = (new ComprobanteService())->emitirDeTransaccion((int)$idTx, $this->usuario());
            $this->redirect('/dashboard/comprobantes/descargar?id=' . (int)$c['id']);
        } catch (Throwable $e) {
            error_log('[SIRES COMPROBANTE] ' . $e->getMessage());
            $this->flash('No se pudo emitir el comprobante: ' . $e->getMessage(), 'error');
            $this->redirect('/dashboard/payments/detail?id=' . $idReserva);
        }
    }

    /** Resumen de la reserva: cada descarga emite un comprobante nuevo. */
    public function emitirResumen(array $vars): void
    {
        $idReserva = filter_var($_POST['id_reserva'] ?? '', FILTER_VALIDATE_INT);
        try {
            if ($idReserva === false) {
                throw new \Exception('Reserva inválida.');
            }
            $c = (new ComprobanteService())->emitirResumen((int)$idReserva, $this->usuario());
            $this->flash(Comprobante::TITULOS[Comprobante::RESUMEN] . ' N° '
                . Comprobante::numeroFormateado((int)$c['punto_venta'], (int)$c['numero']) . ' emitido.');
            $this->redirect('/dashboard/comprobantes/descargar?id=' . (int)$c['id']);
        } catch (Throwable $e) {
            error_log('[SIRES COMPROBANTE] ' . $e->getMessage());
            $this->flash('No se pudo emitir el resumen: ' . $e->getMessage(), 'error');
            $this->redirect('/dashboard/booking/detail?id=' . (int)$idReserva);
        }
    }

    private function usuario(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}
