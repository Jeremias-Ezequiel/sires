<?php

namespace App\Controllers;

use Exception;
use App\Models\Consumo;
use App\Models\ResumenPago;
use App\Helpers\UrlHelper;

class ConsumoController
{
    public function showConsumos(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

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

        $search = $_GET['search'] ?? '';
        $estado = $_GET['estado'] ?? '';

        $consumoModel = new Consumo();
        $totalConsumos = $consumoModel->countAllWithFilters($search, $estado);
        $totalPages = (int)ceil($totalConsumos / $limit);
        if ($totalPages < 1) { $totalPages = 1; }
        if ($currentPage > $totalPages) { $currentPage = $totalPages; $offset = ($currentPage - 1) * $limit; }

        $consumos = $consumoModel->getAllWithFilters($search, $estado, $limit, $offset);

        $contentView = __DIR__ . '/../views/dashboard/consumos.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function showNewForm(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $old = $_SESSION['old_inputs'] ?? [];
        unset($_SESSION['old_inputs']);

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $idReserva = (int)($_GET['id_reserva'] ?? 0);
        if ($idReserva <= 0) {
            $_SESSION['flash_message'] = "ID de reserva inválido.";
            $_SESSION['flash_status']  = "error";
            header('Location: ' . UrlHelper::to('/dashboard/consumos'));
            exit;
        }

        $contentView = __DIR__ . '/../views/dashboard/addConsumo.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addConsumo(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $idReserva = (int)($_POST['id_reserva'] ?? 0);
            $descripcion = trim($_POST['descripcion'] ?? '');
            $subtotal = (float)($_POST['subtotal'] ?? 0);

            if ($idReserva <= 0) {
                throw new Exception("ID de reserva inválido.");
            }

            if (empty($descripcion)) {
                throw new Exception("La descripción es obligatoria.");
            }

            if ($subtotal <= 0) {
                throw new Exception("El subtotal debe ser mayor a 0.");
            }

            $consumo = new Consumo();
            $consumo->setIdReserva($idReserva);
            $consumo->setDescripcion($descripcion);
            $consumo->setSubtotal($subtotal);
            $consumo->setIdEstado(Consumo::ESTADO_FACTURADO);

            $model = new Consumo();
            if (!$model->save($consumo)) {
                throw new Exception("No se pudo registrar el consumo.");
            }

            // Actualizar resumen de pago
            $resumenModel = new ResumenPago();
            $resumenModel->recalcular($idReserva);

            $_SESSION['flash_message'] = "Consumo registrado exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/consumos'));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/consumos/add?id_reserva=' . ($_POST['id_reserva'] ?? 0)));
            exit;
        }
    }

    public function showDetail(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $id = (int)($_GET['id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['flash_message'] = "ID de consumo inválido.";
            $_SESSION['flash_status']  = "error";
            header('Location: ' . UrlHelper::to('/dashboard/consumos'));
            exit;
        }

        $consumoModel = new Consumo();
        $consumo = $consumoModel->findById($id);

        if (!$consumo) {
            $_SESSION['flash_message'] = "El consumo no existe.";
            $_SESSION['flash_status']  = "error";
            header('Location: ' . UrlHelper::to('/dashboard/consumos'));
            exit;
        }

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $contentView = __DIR__ . '/../views/dashboard/detailConsumo.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function facturar(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new Exception("ID de consumo inválido.");
            }

            $model = new Consumo();
            if (!$model->cambiarEstado($id, Consumo::ESTADO_FACTURADO)) {
                throw new Exception("No se pudo facturar el consumo.");
            }

            // Actualizar resumen de pago
            $consumo = $model->findById($id);
            if ($consumo) {
                $resumenModel = new ResumenPago();
                $resumenModel->recalcular((int)$consumo['id_reserva']);
            }

            $_SESSION['flash_message'] = "Consumo facturado exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/consumos'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/consumos'));
            exit;
        }
    }

    public function anular(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new Exception("ID de consumo inválido.");
            }

            $model = new Consumo();
            if (!$model->cambiarEstado($id, Consumo::ESTADO_ANULADO)) {
                throw new Exception("No se pudo anular el consumo.");
            }

            // Actualizar resumen de pago
            $consumo = $model->findById($id);
            if ($consumo) {
                $resumenModel = new ResumenPago();
                $resumenModel->recalcular((int)$consumo['id_reserva']);
            }

            $_SESSION['flash_message'] = "Consumo anulado exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/consumos'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/consumos'));
            exit;
        }
    }
}
