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

/**
 * IP real del cliente, para throttling y auditoria.
 *
 * Solo se lee REMOTE_ADDR a proposito: X-Forwarded-For lo manda el cliente y
 * cualquier visitante lo puede forjar para esquivar el limite de intentos. Si
 * algun dia se pone la app detras de un proxy, hay que corregir esto a mano
 * con la IP real del proxy, no confiando en la cabecera.
 *
 * @return string|null null solo en CLI, donde no hay request.
 */
function client_ip(): ?string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!is_string($ip) || $ip === '') {
        return null;
    }
    // 45 es el largo maximo de una IPv6 con zona.
    return substr($ip, 0, 45);
}

/**
 * User-Agent recortado, solo para auditoria. Viene del cliente, asi que se
 * acota el largo para no guardarle basura gigante en la base.
 */
function client_user_agent(int $max = 255): ?string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if (!is_string($ua) || $ua === '') {
        return null;
    }
    return substr($ua, 0, $max);
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
