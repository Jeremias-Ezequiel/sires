<?php

declare(strict_types=1);

namespace App\Controllers;

use Exception;
use App\Models\Habitacion;
use App\Models\MotivoBloqueo;
use App\Services\HabitacionService;
use App\Services\LimpiezaService;

class RoomController extends Controller
{
    private const ID_INVALIDO = 'ID de habitación inválido.';

    private HabitacionService $habitacionService;
    private LimpiezaService $limpiezaService;

    public function __construct()
    {
        $this->habitacionService = new HabitacionService();
        $this->limpiezaService   = new LimpiezaService();
    }

    public function showRooms(array $vars): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $search = $vars['search'] ?? "";
        $status = $vars['status_filter'] ?? "";
        $type   = $vars['type_filter'] ?? "";
        $floor  = $vars['floor_filter'] ?? "";
        $showInactive = $vars['show'] ?? '';

        $hasSearch = !empty($vars['search']);
        $hasStatus = isset($vars['status_filter']) && $vars['status_filter'] !== '';
        $hasType   = isset($vars['type_filter']) && $vars['type_filter'] !== '';
        $hasFloor  = isset($vars['floor_filter']) && $vars['floor_filter'] !== '';

        $roomModel = new Habitacion();
        $soloInactivas = $showInactive === 'inactive' ? '1' : null;

        $currentPage = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;

        $totalHabitaciones = $roomModel->countWithFilters($search, $status, $type, $floor, $soloInactivas);
        $totalPages = max(1, (int)ceil($totalHabitaciones / $limit));
        $currentPage = min($currentPage, $totalPages);

        $habitaciones = $roomModel->getAllWithFilters(
            $search, $status, $type, $floor, $soloInactivas,
            $limit, ($currentPage - 1) * $limit
        );
        $tipos = $roomModel->getTiposHabitacion();
        $estados = $roomModel->getEstadosHabitacion();
        $pisos = $roomModel->getPisos();
        $motivos = (new MotivoBloqueo())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/rooms.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function showNewRoomForm(): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $old = $this->tomarInputs();

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $roomModel = new Habitacion();
        $tipos = $roomModel->getTiposHabitacion();
        $estados = $roomModel->getEstadosHabitacion();
        $pisos = $roomModel->getPisos();
        $todasHabitaciones = $roomModel->getAllWithFilters(null, null, null, null);

        $contentView = __DIR__ . '/../views/dashboard/addRoom.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addRoom(): void
    {
        $this->ejecutarAccion(
            fn() => $this->habitacionService->altaIndividual(
                (int)($_POST['piso'] ?? -1),
                (int)($_POST['id_tipo_habitacion'] ?? 0),
                (float)($_POST['precio_noche_base'] ?? 0)
            ),
            '/dashboard/rooms', '/dashboard/rooms/add', true
        );
    }

    public function showEditRoomForm(array $vars): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $old = $this->tomarInputs();

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        try {
            $id = $this->idDe('id', self::ID_INVALIDO, $vars);

            $roomModel = new Habitacion();
            $habitacion = $roomModel->findById($id);

            if (!$habitacion) {
                throw new Exception("La habitación no existe.");
            }

            $tipos = $roomModel->getTiposHabitacion();
            $estados = $roomModel->getEstadosHabitacion();

            $contentView = __DIR__ . '/../views/dashboard/editRoom.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');
            $this->redirect('/dashboard/rooms');
        }
    }

    public function editRoom(): void
    {
        $this->ejecutarAccion(
            fn() => $this->habitacionService->editar(
                (int)($_POST['id'] ?? 0),
                (int)($_POST['numero'] ?? 0),
                (int)($_POST['piso'] ?? -1),
                (int)($_POST['id_tipo_habitacion'] ?? 0),
                (int)($_POST['id_estado_habitacion'] ?? 0),
                (float)($_POST['precio_noche_base'] ?? 0)
            ),
            '/dashboard/rooms', '/dashboard/rooms/edit?id=' . (int)($_POST['id'] ?? 0), true
        );
    }

    public function showBatchRoomForm(): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $old = $this->tomarInputs();

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $roomModel = new Habitacion();
        $tipos = $roomModel->getTiposHabitacion();
        $pisos = $roomModel->getPisos();
        $nextFloor = $roomModel->getNextFloorNumber();

        $contentView = __DIR__ . '/../views/dashboard/addRoomBatch.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addBatchRoom(): void
    {
        $this->ejecutarAccion(
            fn() => $this->habitacionService->altaMasiva(
                (int)($_POST['piso'] ?? -1),
                (int)($_POST['id_tipo_habitacion'] ?? 0),
                (float)($_POST['precio_noche_base'] ?? 0),
                (int)($_POST['numero_desde'] ?? 0),
                (int)($_POST['numero_hasta'] ?? 0)
            ),
            '/dashboard/rooms', '/dashboard/rooms/add/batch', true
        );
    }

    public function showRoomDetail(array $vars): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        try {
            $id = $this->idDe('id', self::ID_INVALIDO, $vars);

            $roomModel = new Habitacion();
            $habitacion = $roomModel->findById($id);

            if (!$habitacion) {
                throw new Exception("La habitación no existe.");
            }

            // Historial de auditoría: solo administrador y gerente
            $historial = null;
            if (\App\Middleware\AuthMiddleware::hasRole([\App\Models\Rol::ADMINISTRADOR, \App\Models\Rol::GERENTE])) {
                try {
                    $historial = (new \App\Services\AuditoriaService())->historial('habitacion', $id);
                } catch (Exception $e) {
                    error_log("[SIRES AUDITORIA] " . $e->getMessage());
                }
            }

            $contentView = __DIR__ . '/../views/dashboard/detailRoom.phtml';
            require_once __DIR__ . '/../views/dashboard/layout.phtml';

        } catch (Exception $e) {
            $this->flash($e->getMessage(), 'error');
            $this->redirect('/dashboard/rooms');
        }
    }

    public function deactivateRoom(array $vars): void
    {
        $this->ejecutarAccion(function (): array {
            $motivo = filter_var($_POST['motivo'] ?? '', FILTER_VALIDATE_INT);
            return $this->habitacionService->bloquear(
                $this->idDe('id', self::ID_INVALIDO),
                $motivo !== false ? $motivo : null
            );
        }, '/dashboard/rooms');
    }

    public function activateRoom(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->habitacionService->desbloquear($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/rooms'
        );
    }

    public function bajaLogicaRoom(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->habitacionService->darDeBaja($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/rooms'
        );
    }

    public function reactivateRoom(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->habitacionService->reactivar($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/rooms'
        );
    }

    public function startCleaning(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->limpiezaService->iniciarLimpieza($this->idDe('id', self::ID_INVALIDO)),
            $this->rutaVueltaLimpieza()
        );
    }

    public function completeCleaning(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->limpiezaService->completarLimpieza($this->idDe('id', self::ID_INVALIDO)),
            $this->rutaVueltaLimpieza()
        );
    }

    public function putInMaintenance(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->habitacionService->ponerEnMantenimiento($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/rooms'
        );
    }

    public function outOfMaintenance(array $vars): void
    {
        $this->ejecutarAccion(
            fn() => $this->habitacionService->salirDeMantenimiento($this->idDe('id', self::ID_INVALIDO)),
            '/dashboard/rooms'
        );
    }

    /**
     * Las acciones de limpieza se disparan desde Habitaciones o desde Housekeeping:
     * se vuelve a la pantalla de origen (valor de una lista fija, nunca una URL del usuario).
     */
    private function rutaVueltaLimpieza(): string
    {
        return ($_POST['volver'] ?? '') === 'housekeeping' ? '/dashboard/housekeeping' : '/dashboard/rooms';
    }
}
