<?php

namespace App\Controllers;

use Exception;
use App\Models\Habitacion;
use App\Models\Reserva;
use App\Models\Rol;
use App\Models\TransaccionPago;

class DashboardController
{
    public function showHome(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $rolMap = [
            Rol::ADMINISTRADOR => 'Administrador',
            Rol::RECEPCIONISTA => 'Recepcionista',
            Rol::GERENTE       => 'Gerente',
            Rol::AUDITOR       => 'Auditor',
        ];
        $rolNombre = $rolMap[$userRole] ?? 'Sin rol';

        $capacidad_filtrada = isset($vars['capacidad']) ? (int)$vars['capacidad'] : 2;
        $errorMessage = '';

        $stats = [
            'disponibles'   => 0,
            'ocupadas'      => 0,
            'reservadas'    => 0,
            'ingresos_hoy'  => 0.0,
            'capacidades'   => [],
            'habitaciones'  => [],
        ];

        try {
            $habitacion  = new Habitacion();
            $reserva     = new Reserva();
            $transaccion = new TransaccionPago();

            $stats['disponibles']  = $habitacion->countByEstado(Habitacion::ESTADO_DISPONIBLE);
            $stats['ocupadas']     = $habitacion->countByEstado(Habitacion::ESTADO_OCUPADA);
            $stats['reservadas']   = $reserva->countReservasHoy();
            $stats['ingresos_hoy'] = $transaccion->sumIngresosDelDia();

            // Las capacidades vienen de Tipos_Habitacion, no de una lista fija
            // en el PHP: si se da de alta un tipo de 5 personas, el panel lo
            // muestra sin tocar este archivo.
            $stats['capacidades'] = [];
            foreach ($habitacion->capacidadesExistentes() as $capacidad) {
                $stats['capacidades'][] = [
                    'capacidad'   => $capacidad,
                    'disponibles' => $habitacion->countDisponiblesPorCapacidad($capacidad),
                ];
            }

            // Si piden una capacidad que no existe (un link viejo, un typo), se
            // cae a la primera real en vez de mostrar una lista vacia sin
            // explicar por que.
            if ($stats['capacidades'] !== []
                && !in_array($capacidad_filtrada, array_column($stats['capacidades'], 'capacidad'), true)) {
                $capacidad_filtrada = $stats['capacidades'][0]['capacidad'];
            }

            $stats['habitaciones'] = $habitacion->getHabitacionesPorCapacidad($capacidad_filtrada);
        } catch (Exception $e) {
            error_log("Error en Dashboard: " . $e->getMessage());
            $errorMessage = "Error de datos.";
        }

        extract($stats, EXTR_SKIP);

        $fecha_formateada = date('d/m/Y');

        $esAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($esAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success'      => empty($errorMessage),
                'fecha'        => $fecha_formateada,
                'habitaciones' => array_map(static function (array $room): array {
                    return [
                        'numero' => $room['numero'],
                        'piso'   => $room['piso'],
                        'estado' => $room['estado'],
                        'tipo'   => $room['tipo'],
                    ];
                }, $habitaciones),
            ]);
            exit;
        }

        $contentView = __DIR__ . '/../views/dashboard/home.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }
}