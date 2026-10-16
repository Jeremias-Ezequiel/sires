<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Provincia;
use App\Models\Localidad;

class ApiController
{
    public function getProvinces(array $vars): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $nacionalidadId = isset($vars['nacionalidadId']) ? (int) $vars['nacionalidadId'] : 0;

        if ($nacionalidadId <= 0) {
            echo json_encode([]);
            return;
        }

        $model = new Provincia();
        $provinces = $model->getByPais($nacionalidadId);

        $result = [];
        if (!empty($provinces)) {
            foreach ($provinces as $p) {
                $result[] = [
                    'id' => $p->getId(),
                    'descripcion' => $p->getDescripcion(),
                ];
            }
        }

        echo json_encode($result);
    }

    public function getCities(array $vars): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $provinciaId = isset($vars['provinciaId']) ? (int) $vars['provinciaId'] : 0;

        if ($provinciaId <= 0) {
            echo json_encode([]);
            return;
        }

        $model = new Localidad();
        $cities = $model->getByProvincia($provinciaId);

        $result = [];
        if (!empty($cities)) {
            foreach ($cities as $c) {
                $result[] = [
                    'id' => $c->getId(),
                    'descripcion' => $c->getDescripcion(),
                ];
            }
        }

        echo json_encode($result);
    }

    public function consultarReloj(array $vars): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $url = $_ENV['API_RELOJ_URL'] ?? '';
        $user = $_ENV['API_RELOJ_USER'] ?? '';
        $pass = $_ENV['API_RELOJ_PASS'] ?? '';

        if ($url === '' || $user === '' || $pass === '') {
            echo $this->renderError('Variables de entorno API_RELOJ_URL, API_RELOJ_USER o API_RELOJ_PASS no configuradas.');
            return;
        }

        $endpoint = rtrim($url, '/') . '/api/index.php';

        try {
            $credentials = base64_encode($user . ':' . $pass);
            $opts = stream_context_create();
            stream_context_set_option($opts, 'http', 'method', 'GET');
            stream_context_set_option($opts, 'http', 'header', 'Authorization: Basic ' . $credentials . "\r\nAccept: application/json");

            $response = file_get_contents($endpoint, false, $opts);

            if ($response === false || $response === null || $response === '') {
                echo $this->renderError('No se recibió respuesta de la API.');
                return;
            }

            $data = json_decode($response, true);

            if (!is_array($data) || !isset($data['codigo_confirmacion'])) {
                echo $this->renderError('La respuesta de la API no contiene el formato esperado.');
                return;
            }

            echo $this->renderSuccess($data);
        } catch (Exception $e) {
            echo $this->renderError('Error al conectar con la API: ' . $e->getMessage());
        }
    }

    private function renderSuccess(array $data): string
    {
        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">';
        $html .= '<title>Consulta de Reloj Oficial</title>';
        $html .= '<style>';
        $html .= 'body{font-family:sans-serif;background:#f5f5f5;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0}';
        $html .= '.card{background:#fff;border-radius:12px;padding:2rem;max-width:520px;width:90%;margin:2rem auto;box-shadow:0 4px 20px rgba(0,0,0,.12)}';
        $html .= 'h1{font-size:1.3rem;color:#333;margin-top:0;text-align:center}';
        $html .= '.codigo{background:#2e7d32;color:#fff;font-size:2rem;text-align:center;padding:.75rem;border-radius:8px;margin:1rem 0;letter-spacing:2px;font-family:monospace}';
        $html .= 'table{width:100%;border-collapse:collapse}';
        $html .= 'td{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0}';
        $html .= 'td:first-child{font-weight:600;color:#555;width:40%}';
        $html .= 'td:last-child{color:#222}';
        $html .= '</style></head><body>';
        $html .= '<div class="card">';
        $html .= '<h1>Confirmación Horaria</h1>';
        $html .= '<div class="codigo">' . htmlspecialchars($data['codigo_confirmacion'], ENT_QUOTES) . '</div>';
        $html .= '<table>';
        $html .= '<tr><td>Fecha</td><td>' . htmlspecialchars($data['fecha'] ?? '', ENT_QUOTES) . '</td></tr>';
        $html .= '<tr><td>Hora</td><td>' . htmlspecialchars($data['hora'] ?? '', ENT_QUOTES) . '</td></tr>';
        $html .= '<tr><td>ID Registro</td><td>' . htmlspecialchars((string)($data['id_registro'] ?? ''), ENT_QUOTES) . '</td></tr>';
        $html .= '<tr><td>Timestamp</td><td>' . htmlspecialchars((string)($data['timestamp'] ?? ''), ENT_QUOTES) . '</td></tr>';
        $html .= '<tr><td>Zona Horaria</td><td>' . htmlspecialchars($data['zona_horaria'] ?? '', ENT_QUOTES) . '</td></tr>';
        $html .= '</table>';
        $html .= '<p style="text-align:center;margin-top:1rem"><a href="' . url('/api/reloj') . '" style="background:#1976d2;color:#fff;padding:.5rem 1.5rem;border-radius:6px;text-decoration:none">Nueva Consulta</a></p>';
        $html .= '</div></body></html>';
        return $html;
    }

    private function renderError(string $message): string
    {
        $msg = htmlspecialchars($message, ENT_QUOTES);
        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">';
        $html .= '<title>Error - Reloj</title>';
        $html .= '<style>';
        $html .= 'body{font-family:sans-serif;background:#f5f5f5;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0}';
        $html .= '.card{background:#fff;border-radius:12px;padding:2rem;max-width:520px;width:90%;margin:2rem auto;box-shadow:0 4px 20px rgba(0,0,0,.12)}';
        $html .= 'h1{font-size:1.3rem;color:#333;margin-top:0;text-align:center}';
        $html .= '.error{background:#d32f2f;color:#fff;padding:1rem;border-radius:8px;margin:1rem 0;font-size:1rem;text-align:center}';
        $html .= '</style></head><body>';
        $html .= '<div class="card">';
        $html .= '<h1>Error de Consulta</h1>';
        $html .= '<div class="error">' . $msg . '</div>';
        $html .= '<p style="text-align:center;margin-top:1rem"><a href="' . url('/api/reloj') . '" style="background:#1976d2;color:#fff;padding:.5rem 1.5rem;border-radius:6px;text-decoration:none;display:inline-block">Reintentar</a></p>';
        $html .= '</div></body></html>';
        return $html;
    }
}
