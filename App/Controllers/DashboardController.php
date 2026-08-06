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
            'disponibles'     => 0,
            'ocupadas'        => 0,
            'reservadas'      => 0,
            'ingresos_hoy'    => 0.0,
            'disp_2_personas' => 0,
            'disp_3_personas' => 0,
            'disp_4_personas' => 0,
            'habitaciones'    => [],
        ];

        try {
            $habitacion  = new Habitacion();
            $reserva     = new Reserva();
            $transaccion = new TransaccionPago();

            $stats['disponibles']     = $habitacion->countByEstado(Habitacion::ESTADO_DISPONIBLE);
            $stats['ocupadas']        = $habitacion->countByEstado(Habitacion::ESTADO_OCUPADA);
            $stats['reservadas']      = $reserva->countReservasHoy();
            $stats['ingresos_hoy']    = $transaccion->sumIngresosDelDia();
            $stats['disp_2_personas'] = $habitacion->countDisponiblesPorCapacidad(2);
            $stats['disp_3_personas'] = $habitacion->countDisponiblesPorCapacidad(3);
            $stats['disp_4_personas'] = $habitacion->countDisponiblesPorCapacidad(4);
            $stats['habitaciones']    = $habitacion->getHabitacionesPorCapacidad($capacidad_filtrada);
        } catch (Exception $e) {
            error_log("Error en Dashboard: " . $e->getMessage());
            $errorMessage = "Error de datos.";
        }

        extract($stats, EXTR_SKIP);

        $fecha_formateada = date('d/m/Y');

        $contentView = __DIR__ . '/../views/dashboard/home.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }
}
