<?php

declare(strict_types=1);
use App\Helpers\UrlHelper;

function url(string $path): string {
    return UrlHelper::to($path);
}

function asset(string $path): string {
    return UrlHelper::asset($path);
}

/**
 * URL de un asset con ?v=<fecha de modificación> para que el navegador
 * descargue la versión nueva cada vez que cambia el archivo.
 */
function asset_v(string $path): string {
    $archivo = dirname(__DIR__, 2) . '/public/' . ltrim($path, '/');
    $version = is_file($archivo) ? (string)filemtime($archivo) : '1';
    return UrlHelper::asset($path) . '?v=' . $version;
}

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

function csrf_check(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $_POST['_csrf_token'] ?? '';
    $expected = $_SESSION['_csrf_token'] ?? '';
    if (empty($expected) || !hash_equals($expected, $token)) {
        throw new \Exception("Token de seguridad inválido. Intente nuevamente.");
    }
}
