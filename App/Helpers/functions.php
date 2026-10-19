<?php
use App\Helpers\UrlHelper;

function url(string $path): string {
    return UrlHelper::to($path);
}

function asset(string $path): string {
    return UrlHelper::asset($path);
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

function business_rules(?string $path = null) {
    $config = require __DIR__ . '/../Config/BusinessRules.php';

    if ($path === null) {
        return $config;
    }

    $current = $config;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return null;
        }
        $current = $current[$segment];
    }

    return $current;
}
