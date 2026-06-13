<?php

namespace App\Controllers;

use PDO;
use Exception;

class DashboardController
{
    public function showHome(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userName = $_SESSION['user_name'] ?? 'Usuario';
        $userRole = $_SESSION['user_role'] ?? 0;
        $errorMessage = '';

        // Variables iniciales
        $disponibles        = 0;
        $ocupadas           = 0;
        $reservadas         = 0;
        $ingresos_hoy       = 0;

        $capacidad_filtrada = isset($_GET['capacidad']) ? (int)$_GET['capacidad'] : 2;
        $disp_2_personas    = 0;
        $disp_3_personas    = 0;
        $disp_4_personas    = 0;

        $habitaciones       = [];

        try {
            $dbConfig = new \App\Config\Database();
            $pdo = $dbConfig->getConnection();

            if ($pdo) {
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // 1. Contadores maestros (Siempre se calculan)
                $disponibles = (int)$pdo->query("SELECT COUNT(*) FROM Habitaciones WHERE id_estado_habitacion = 1")->fetchColumn();
                $ocupadas    = (int)$pdo->query("SELECT COUNT(*) FROM Habitaciones WHERE id_estado_habitacion = 2")->fetchColumn();

                $disp_2_personas = (int)$pdo->query("SELECT COUNT(*) FROM Habitaciones WHERE id_estado_habitacion = 1 AND tipo_habitacion IN (1, 4)")->fetchColumn();
                $disp_3_personas = (int)$pdo->query("SELECT COUNT(*) FROM Habitaciones WHERE id_estado_habitacion = 1 AND tipo_habitacion = 2")->fetchColumn();
                $disp_4_personas = (int)$pdo->query("SELECT COUNT(*) FROM Habitaciones WHERE id_estado_habitacion = 1 AND tipo_habitacion = 3")->fetchColumn();

                // 2. Filtrado de la tabla según la capacidad requerida
                $tipos_ids = ($capacidad_filtrada === 3) ? [2] : (($capacidad_filtrada === 4) ? [3] : [1, 4]);
                $placeholders = implode(',', array_fill(0, count($tipos_ids), '?'));

                $sqlTable = "SELECT h.numero, h.piso, th.descripcion AS tipo, eh.descripcion AS estado
                             FROM Habitaciones h
                             JOIN Tipos_Habitacion th ON h.tipo_habitacion = th.id
                             JOIN Estados_Habitacion eh ON h.id_estado_habitacion = eh.id
                             WHERE h.tipo_habitacion IN ($placeholders)
                             ORDER BY h.numero ASC";

                $stmtTable = $pdo->prepare($sqlTable);
                $stmtTable->execute($tipos_ids);
                $habitaciones = $stmtTable->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Error en Dashboard: " . $e->getMessage());
            $errorMessage = "Error de datos.";
        }

        // 🌟 CLAVE: Si la petición viene por Fetch/AJAX, devolvemos SOLO un JSON con los datos de la tabla
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'capacidad' => $capacidad_filtrada,
                'habitaciones' => $habitaciones
            ]);
            exit;
        }

        // Renderizado normal de la página entera si no es AJAX
        $fecha_formateada = date('d/m/Y');
        try {
            $formatter = new \IntlDateFormatter('es_AR', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'America/Argentina/Buenos_Aires');
            if ($formatter) $fecha_formateada = ucfirst($formatter->format(new \DateTime()));
        } catch (Exception $e) {
        }

        $contentView = __DIR__ . '/../views/dashboard/home.phtml';
        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }
}

