<?php

declare(strict_types=1);

namespace App\Controllers;

use Exception;
use App\Services\ConfiguracionAdminService;

/** Pantalla de configuración de políticas de negocio (solo administrador). */
class ConfiguracionController extends Controller
{
    public function mostrar(array $vars): void
    {
        $flashMessage = $_SESSION['flash_message'] ?? '';
        $flashStatus  = $_SESSION['flash_status'] ?? '';
        unset($_SESSION['flash_message'], $_SESSION['flash_status']);

        $errorMessage = '';
        $panel = [];
        try {
            $panel = (new ConfiguracionAdminService())->obtenerPanel();
        } catch (Exception $e) {
            error_log("Error en Configuracion: " . $e->getMessage());
            $errorMessage = "No se pudo cargar la configuración.";
        }

        $pageTitle   = 'Configuración | SIRES';
        $contentView = __DIR__ . '/../views/dashboard/configuracion.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function guardar(array $vars): void
    {
        $clave = (string)($_POST['clave'] ?? '');
        try {
            $mensaje = (new ConfiguracionAdminService())->programarCambio(
                $clave,
                $_POST,
                ($_POST['cuando'] ?? '') === 'programar' ? 'programar' : 'ahora',
                (string)($_POST['vigente_desde'] ?? ''),
                (int)($_SESSION['user_id'] ?? 0)
            );
            $this->flash($mensaje);
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');
        }
        $this->redirect('/dashboard/settings');
    }
}
