<?php

declare(strict_types=1);

namespace App\Controllers;

use Exception;
use App\Models\Rol;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    public function showHome(array $vars): void
    {
        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = (int)($_SESSION['user_role'] ?? 0);

        $rolMap = [
            Rol::ADMINISTRADOR => 'Administrador',
            Rol::RECEPCIONISTA => 'Recepcionista',
            Rol::GERENTE       => 'Gerente',
            Rol::LIMPIEZA      => 'Personal de Limpieza',
        ];
        $rolNombre = $rolMap[$userRole] ?? 'Sin rol';

        // Limpieza solo ve el estado de las habitaciones (no tiene acceso a reservas ni pagos)
        $verOperacion = in_array($userRole, [Rol::ADMINISTRADOR, Rol::GERENTE, Rol::RECEPCIONISTA], true);

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $flashMessage = $_SESSION['flash_message'] ?? '';
        $flashStatus  = $_SESSION['flash_status'] ?? '';
        unset($_SESSION['flash_message'], $_SESSION['flash_status']);

        $tablero = null;
        try {
            $tablero = (new DashboardService())->obtenerTablero();
        } catch (Exception $e) {
            error_log("Error en Dashboard: " . $e->getMessage());
            $errorMessage = "No se pudieron cargar los datos del tablero.";
        }

        $contentView = __DIR__ . '/../views/dashboard/home.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }
}
