<?php

declare(strict_types=1);

namespace App\Controllers;

use Exception;
use App\Services\PlanningService;

/**
 * Planning de ocupación tipo Gantt: habitaciones en filas, días en columnas (14 o 30)
 * y reservas como barras. Clic en una barra abre la reserva; en una celda libre, una
 * reserva nueva con la habitación y la fecha precargadas.
 */
class PlanningController extends Controller
{
    public function mostrar(array $vars): void
    {
        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = (int)($_SESSION['user_role'] ?? 0);

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $planning = null;
        try {
            $planning = (new PlanningService())->obtener($vars['desde'] ?? null, $vars['dias'] ?? null);
        } catch (Exception $e) {
            error_log('[SIRES PLANNING] ' . $e->getMessage());
            $errorMessage = 'No se pudo cargar el planning.';
        }

        $contentView = __DIR__ . '/../views/dashboard/planning.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }
}
