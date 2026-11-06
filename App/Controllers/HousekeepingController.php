<?php

declare(strict_types=1);

namespace App\Controllers;

use Exception;
use App\Middleware\AuthMiddleware;
use App\Models\Rol;
use App\Services\LimpiezaService;

/**
 * Housekeeping: lista de habitaciones para limpiar, en el orden sugerido por
 * LimpiezaService::obtenerTablero(). Es la pantalla de inicio del rol Limpieza.
 */
class HousekeepingController extends Controller
{
    /** Roles que pueden marcar una limpieza como urgente (Limpieza no). */
    public const ROLES_MARCAR_URGENTE = [Rol::ADMINISTRADOR, Rol::GERENTE, Rol::RECEPCIONISTA];

    public function mostrar(array $vars): void
    {
        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = (int)($_SESSION['user_role'] ?? 0);

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $flashMessage = $_SESSION['flash_message'] ?? '';
        $flashStatus  = $_SESSION['flash_status'] ?? '';
        unset($_SESSION['flash_message'], $_SESSION['flash_status']);

        $puedeMarcarUrgente = AuthMiddleware::hasRole(self::ROLES_MARCAR_URGENTE);

        $tablero = null;
        try {
            $tablero = (new LimpiezaService())->obtenerTablero();
        } catch (Exception $e) {
            error_log("Error en Housekeeping: " . $e->getMessage());
            $errorMessage = "No se pudo cargar la lista de limpieza.";
        }

        $contentView = __DIR__ . '/../views/dashboard/housekeeping.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function marcarUrgente(array $vars): void
    {
        try {
            $id = $_POST['id'] ?? '';
            if (filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de habitación inválido.");
            }
            $urgente = ($_POST['urgente'] ?? '') === '1';

            $resultado = (new LimpiezaService())->marcarUrgente((int)$id, $urgente);
            $this->flash($resultado['message']);
        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');
        }

        $this->redirect('/dashboard/housekeeping');
    }
}
