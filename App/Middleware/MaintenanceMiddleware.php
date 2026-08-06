<?php

declare(strict_types=1);

namespace App\Middleware;

class MaintenanceMiddleware
{
    public static function check(): void
    {
        // Esto lo podrías leer de una variable en tu archivo .env (ej: MAINTENANCE_MODE=true)
        $underMaintenance = true;

        if ($underMaintenance) {
            // Código HTTP 503: Service Unavailable (El código correcto para SEO y servidores)
            http_response_code(503);

            // Cargamos la vista directo o redirigimos
            require_once __DIR__ . '/../views/errors/maintenance.phtml';
            exit; // Cortamos el index.php en seco, nadie entra al controlador
        }
    }
}
