<?php

namespace App\Controllers;

use Exception;
use App\Models\Impuesto;
use App\Helpers\UrlHelper;

class ImpuestoController
{
    public function showImpuestos(): void
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

        $impuestoModel = new Impuesto();
        $impuestos = $impuestoModel->getAll();

        $contentView = __DIR__ . '/../views/dashboard/impuestos.phtml';

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

        $contentView = __DIR__ . '/../views/dashboard/addImpuesto.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addImpuesto(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $nombre = trim($_POST['nombre'] ?? '');
            $porcentaje = (float)($_POST['porcentaje'] ?? 0);
            $tipo = $_POST['tipo'] ?? Impuesto::TIPO_IVA;

            if (empty($nombre)) {
                throw new Exception("El nombre del impuesto es obligatorio.");
            }

            if ($porcentaje <= 0 || $porcentaje > 100) {
                throw new Exception("El porcentaje debe ser entre 0 y 100.");
            }

            $impuesto = new Impuesto();
            $impuesto->setNombre($nombre);
            $impuesto->setPorcentaje($porcentaje);
            $impuesto->setTipo($tipo);
            $impuesto->setIsActive(true);

            $model = new Impuesto();
            if (!$model->save($impuesto)) {
                throw new Exception("No se pudo registrar el impuesto.");
            }

            $_SESSION['flash_message'] = "Impuesto registrado exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/impuestos'));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/impuestos/add'));
            exit;
        }
    }

    public function showEditForm(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $id = (int)($_GET['id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['flash_message'] = "ID de impuesto inválido.";
            $_SESSION['flash_status']  = "error";
            header('Location: ' . UrlHelper::to('/dashboard/impuestos'));
            exit;
        }

        $impuestoModel = new Impuesto();
        $impuesto = $impuestoModel->findById($id);

        if (!$impuesto) {
            $_SESSION['flash_message'] = "El impuesto no existe.";
            $_SESSION['flash_status']  = "error";
            header('Location: ' . UrlHelper::to('/dashboard/impuestos'));
            exit;
        }

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $contentView = __DIR__ . '/../views/dashboard/editImpuesto.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function editImpuesto(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $porcentaje = (float)($_POST['porcentaje'] ?? 0);
            $tipo = $_POST['tipo'] ?? Impuesto::TIPO_IVA;

            if ($id <= 0) {
                throw new Exception("ID de impuesto inválido.");
            }

            if (empty($nombre)) {
                throw new Exception("El nombre del impuesto es obligatorio.");
            }

            if ($porcentaje <= 0 || $porcentaje > 100) {
                throw new Exception("El porcentaje debe ser entre 0 y 100.");
            }

            $impuesto = new Impuesto();
            $impuesto->setId($id);
            $impuesto->setNombre($nombre);
            $impuesto->setPorcentaje($porcentaje);
            $impuesto->setTipo($tipo);
            $impuesto->setIsActive(true);

            $model = new Impuesto();
            if (!$model->update($impuesto)) {
                throw new Exception("No se pudo actualizar el impuesto.");
            }

            $_SESSION['flash_message'] = "Impuesto actualizado exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/impuestos'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/impuestos/edit?id=' . ($_POST['id'] ?? 0)));
            exit;
        }
    }

    public function deleteImpuesto(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new Exception("ID de impuesto inválido.");
            }

            $model = new Impuesto();
            if (!$model->delete($id)) {
                throw new Exception("No se pudo eliminar el impuesto.");
            }

            $_SESSION['flash_message'] = "Impuesto eliminado exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/impuestos'));
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            header('Location: ' . UrlHelper::to('/dashboard/impuestos'));
            exit;
        }
    }
}
