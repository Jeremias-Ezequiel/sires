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

function csrf_check_query(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $_GET['_csrf_token'] ?? '';
    $expected = $_SESSION['_csrf_token'] ?? '';
    if (empty($expected) || !hash_equals($expected, $token)) {
        throw new \Exception("Token de seguridad inválido. Intente nuevamente.");
    }
}

function csrf_token_query(string $url): string {
    return str_contains($url, '?') ? $url . '&_csrf_token=' . csrf_token() : $url . '?_csrf_token=' . csrf_token();
}
