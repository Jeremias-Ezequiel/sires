<?php

namespace App\Controllers;

use Exception;
use App\Models\Reserva;
use App\Models\ResumenPago;
use App\Models\TransaccionPago;
use App\Models\Auditoria;
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

            // Clave de idempotencia. La manda el hidden input del formulario y
            // sirve para que un reenvio (doble click, F5, respuesta perdida)
            // no cobre dos veces. Si viene vacia o con formato raro, se genera
            // una aca: peor que no proteger, pero nunca romper el pago legitimo
            // de alguien con la pagina cacheada.
            $idempotencyKey = self::normalizarClave($_POST['idempotency_key'] ?? null);
            if ($idempotencyKey === null) {
                $idempotencyKey = bin2hex(random_bytes(16));
            }

            $reserva = (new Reserva())->findById($idReserva);
            if (!$reserva) {
                throw new Exception("La reserva no existe.");
            }

            if ((int)$reserva['id_estado_reserva'] === Reserva::ESTADO_CANCELADA) {
                throw new Exception("No se pueden registrar pagos sobre una reserva cancelada.");
            }

            $resumenModel = new ResumenPago();
            $db = $resumenModel->getConnection();
            $db->beginTransaction();

            try {
                $resumen = $resumenModel->getByReservaForUpdate($idReserva);

                if ($resumen === null) {
                    $resumen = self::generarResumen($reserva, $resumenModel);
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

                // Estado previo del resumen, para que la bitacora guarde el diff
                // y no solo el valor final. Se toma aca, todavia dentro de la
                // transaccion y antes de que $resumen se modifique.
                $montoAntes = $resumen->getMontoPagado();
                $saldoAntes = $resumen->getSaldoPendiente();
                $estadoAntes = $resumen->getIdEstadoPago();

                $transaccion = new TransaccionPago();
                $transaccion->setIdResumenPago($resumen->getId());
                $transaccion->setIdMetodoPago($idMetodoPago);
                $transaccion->setMontoAbonado($montoAbonado);
                $transaccion->setRegistradoPor((int)($_SESSION['user_id'] ?? 0));
                $transaccion->setIdempotencyKey($idempotencyKey);

                $transaccionModel = new TransaccionPago();
                $transaccionModel->setConnection($db);

                // Chequeo previo para el caso normal de un reenvio. Va dentro de
                // la transaccion y sobre la misma conexion para no abrir una
                // segunda. El indice unico sigue cubriendo la carrera entre dos
                // requests simultaneos, que esta consulta no puede evitar.
                if ($transaccionModel->existeClave($idempotencyKey)) {
                    throw new Exception("Este pago ya fue registrado. No se cobró dos veces.");
                }

                $success = $transaccionModel->save($transaccion);
                if (!$success) {
                    throw new Exception("No se pudo registrar la transacción de pago.");
                }

                $nuevoIdTransaccion = (int) $db->lastInsertId();

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

                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            // La auditoria va DESPUES del commit, y por eso solo en el camino
            // de exito. Adentro de la transaccion un rollback borraria el
            // registro junto con el pago, y quedaria el rastro de un cobro que
            // en realidad no ocurrio.
            (new Auditoria())->registrar(
                Auditoria::PAGO,
                'transacciones_pago',
                $nuevoIdTransaccion,
                "Abono de $" . number_format($montoAbonado, 2, ',', '.')
                    . " sobre la reserva #{$idReserva} (saldo: $"
                    . number_format($saldoAntes, 2, ',', '.') . " -> $"
                    . number_format($nuevoSaldo, 2, ',', '.') . ")",
                ['monto_pagado' => $montoAntes, 'saldo_pendiente' => $saldoAntes, 'estado' => $estadoAntes],
                ['monto_pagado' => $nuevoMontoPagado, 'saldo_pendiente' => $nuevoSaldo, 'estado' => $nuevoEstado]
            );

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
     * Valida la clave que manda el navegador. Solo pasa el hex de 32 a 64
     * caracteres, que es lo que genera bin2hex(random_bytes(16)).
     * Devuelve null si no es valida, para que el llamador genere una propia.
     */
    public static function normalizarClave(mixed $cruda): ?string
    {
        if (!is_string($cruda)) {
            return null;
        }

        $limpia = strtolower(trim($cruda));

        return preg_match('/^[0-9a-f]{32,64}$/', $limpia) === 1 ? $limpia : null;
    }

    /**
     * Genera el valor del hidden input idempotency_key del formulario de pago.
     * Se llama desde la vista en cada render, asi que un formulario nuevo
     * siempre arranca con una clave nueva.
     */
    public static function nuevaClaveIdempotencia(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Genera el Resumen_Pago de una reserva calculando el total por noches
     * usando el precio base de la habitación y el descuento por ocupación.
     * Retorna null si no es calculable. Es idempotente: si la reserva ya
     * tiene resumen, lo devuelve sin duplicarlo.
     */
    public static function generarResumen(array $reserva, ?ResumenPago $resumenModel = null): ?ResumenPago
    {
        try {
            if ((int)$reserva['id_estado_reserva'] === Reserva::ESTADO_CANCELADA) {
                return null;
            }

            $resumenModel = $resumenModel ?? new ResumenPago();
            $existente = $resumenModel->getByReserva((int)$reserva['id']);
            if ($existente !== null) {
                return $existente;
            }

            // El desglose lo calcula el modelo: hospedaje por noches mas los
            // consumos cargados. Si se armara el total aca a mano, el detalle
            // volveria a quedar en 0 y el CHECK de la base lo rechazaria.
            // calcularDesglose() tambien valida que la salida sea posterior a
            // la entrada y avisa con un mensaje si las fechas no cierran.
            $desglose = $resumenModel->calcularDesglose($reserva);
            $total = $desglose['total'];

            if ($total <= 0) {
                throw new Exception("No se pudo calcular un total válido para la reserva.");
            }

            $resumen = new ResumenPago();
            $resumen->setIdReserva((int)$reserva['id']);
            $resumen->setIdEstadoPago(ResumenPago::ESTADO_PENDIENTE);
            $resumen->setDesglose($desglose['hospedaje'], $desglose['consumos'], $desglose['total']);
            $resumen->setMontoPagado(0.0);
            $resumen->setSaldoPendiente($total);

            $saved = $resumenModel->save($resumen);
            if (!$saved) {
                throw new Exception("No se pudo crear el resumen de pago.");
            }

            return $resumenModel->getByReserva((int)$reserva['id']);
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";
            return null;
        }
    }
}