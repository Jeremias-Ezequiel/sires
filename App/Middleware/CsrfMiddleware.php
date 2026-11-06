<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\UrlHelper;
use Exception;

class CsrfMiddleware
{
    public static function verify(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method !== 'POST') {
            return;
        }

        try {
            csrf_check();
        } catch (Exception $e) {
            $mensaje = "Token de seguridad inválido. Intente nuevamente.";

            // Las rutas /api/* responden JSON (419 = token vencido o inválido)
            $route = $_GET['route'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $route = str_replace(['/index.php', '/sires'], '', (string)$route);
            if (str_starts_with($route, '/api/')) {
                http_response_code(419);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $mensaje]);
                exit;
            }

            $_SESSION['flash_message'] = $mensaje;
            $_SESSION['flash_status']  = "error";

            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $baseUrl = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

            if ($referer !== '' && str_starts_with($referer, $baseUrl)) {
                header('Location: ' . $referer);
            } else {
                // Sin referer válido: al inicio (el de Limpieza redirige a su lista)
                header('Location: ' . UrlHelper::to('/dashboard'));
            }
            exit;
        }
    }
}