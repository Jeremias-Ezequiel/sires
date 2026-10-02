<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\UrlHelper;

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
            $_SESSION['flash_message'] = "Token de seguridad inválido. Intente nuevamente.";
            $_SESSION['flash_status']  = "error";

            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $baseUrl = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

            if ($referer !== '' && str_starts_with($referer, $baseUrl)) {
                header('Location: ' . $referer);
            } else {
                header('Location: ' . UrlHelper::to('/dashboard/booking'));
            }
            exit;
        }
    }
}