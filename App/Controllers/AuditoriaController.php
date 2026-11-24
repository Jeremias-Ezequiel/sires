<?php

declare(strict_types=1);

namespace App\Controllers;

use Exception;
use App\Services\AuditoriaService;
use App\Services\MonitoreoService;

/**
 * Auditoría (administrador y gerente). Dos vistas:
 *  - acciones: quién hizo qué, con filtros y paginación (AuditoriaService);
 *  - procesos: estado y corridas de los procesos automáticos (MonitoreoService).
 */
class AuditoriaController extends Controller
{
    private const POR_PAGINA = 25;

    public function mostrar(array $vars): void
    {
        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = (int)($_SESSION['user_role'] ?? 0);

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $vista = ($vars['vista'] ?? '') === 'procesos' ? 'procesos' : 'acciones';

        $filtros = [
            'id_usuario' => filter_var($vars['usuario'] ?? '', FILTER_VALIDATE_INT) ?: null,
            'entidad'    => array_key_exists($vars['entidad'] ?? '', AuditoriaService::ENTIDADES) ? $vars['entidad'] : null,
            'id_entidad' => filter_var($vars['id_entidad'] ?? '', FILTER_VALIDATE_INT) ?: null,
            'desde'      => self::fechaValida($vars['desde'] ?? ''),
            'hasta'      => self::fechaValida($vars['hasta'] ?? ''),
        ];
        $hayFiltros = array_filter($filtros, fn($v) => $v !== null) !== [];
        $currentPage = max(1, (int)($vars['page'] ?? 1));

        $registros = [];
        $totalPages = 1;
        $usuarios = [];
        $monitoreo = null;
        $corridas = [];
        $procesoFiltro = isset(MonitoreoService::PROCESOS[$vars['proceso'] ?? '']) ? $vars['proceso'] : null;

        try {
            if ($vista === 'acciones') {
                $auditoria = new AuditoriaService();
                $resultado = $auditoria->listar($filtros, $currentPage, self::POR_PAGINA);
                $registros = $resultado['filas'];
                $totalPages = max(1, (int)ceil($resultado['total'] / self::POR_PAGINA));
                $usuarios = $auditoria->usuariosConRegistros();
            } else {
                $servicio = new MonitoreoService();
                $monitoreo = $servicio->estado();
                $corridas = $servicio->historial($procesoFiltro, 50);
            }
        } catch (Exception $e) {
            error_log("[SIRES AUDITORIA] " . $e->getMessage());
            $errorMessage = "No se pudo cargar la auditoría. Verifique que estén aplicadas las migraciones 012 y 013.";
        }

        $contentView = __DIR__ . '/../views/dashboard/auditoria.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    private static function fechaValida(string $fecha): ?string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $fecha);
        return $dt !== false && $dt->format('Y-m-d') === $fecha ? $fecha : null;
    }
}
