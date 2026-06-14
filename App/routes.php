<?php

declare(strict_types=1);

use FastRoute\RouteCollector;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\EmployeeController;
use App\Controllers\RecoveryController;
use App\Controllers\RoomController;
use App\Middleware\AuthMiddleware;
use App\Middleware\MaintenanceMiddleware;
use App\Middleware\SanitizeLoginMiddleware;
use App\Models\Rol;

return function (RouteCollector $r) {

    // =====================================================================
    // 🔓 RUTAS PÚBLICAS (No requieren verificación de sesión)
    // =====================================================================

    // Soporta la raíz limpia "/" (cuando entrás a /sires/)
    $r->addRoute('GET', '/', [
        'action' => [AuthController::class, 'showLogin']
    ]);

    $r->addRoute('GET', '/sires/', [
        'action' => [AuthController::class, 'showLogin']
    ]);

    // Pantalla de Login explícita
    $r->addRoute('GET', '/sires/login', [
        'action' => [AuthController::class, 'showLogin'],
    ]);

    // Procesamiento del formulario de Login (POST)
    $r->addRoute('POST', '/sires/login/process', [
        'action' => [AuthController::class, 'login']
    ]);

    // Cerrar sesión
    $r->addRoute('GET', '/sires/logout', [
        'action' => [AuthController::class, 'logout']
    ]);

    // 🔑 SISTEMA DE RECUPERACIÓN DE CONTRASEÑA

    // Pantalla 3: Formulario para ingresar el mail
    $r->addRoute('GET', '/sires/password/recovery', [
        'action' => [RecoveryController::class, 'showRecoveryForm']
    ]);

    // Procesamiento de Pantalla 3: Envío de mail real con PHPMailer
    $r->addRoute('POST', '/sires/password/recovery/process', [
        'action' => [RecoveryController::class, 'processRecovery']
    ]);

    // Pantalla 4: Formulario para ingresar la nueva contraseña usando el token dinámico
    $r->addRoute('GET', '/sires/password/reset/{token}', [
        'action' => [RecoveryController::class, 'showResetForm']
    ]);

    // Procesamiento de Pantalla 4: Validación y cambio de clave en la BD
    $r->addRoute('POST', '/sires/password/reset/process', [
        'action' => [RecoveryController::class, 'processReset']
    ]);

    // Botones en mantenimiento
    $r->addRoute('GET', '/sires/maintenance', [
        'middlewares' => [[MaintenanceMiddleware::class, 'check']]
    ]);

    // =====================================================================
    // 🔒 RUTAS PROTEGIDAS (Pasan por la tubería de Middlewares)
    // =====================================================================

    // Panel Principal - Dashboard de SIRES
    $r->addRoute('GET', '/sires/dashboard', [
        'action' => [DashboardController::class, 'showHome'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']]
    ]);

    // Employees
    $r->addRoute('GET', '/sires/dashboard/employees', [
        'action' => [EmployeeController::class, 'showEmployees'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('GET', '/sires/dashboard/employees/add', [
        'action' => [EmployeeController::class, 'showNewEmployeeForm'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('POST', '/sires/dashboard/employees/add/process', [
        'action' => [EmployeeController::class, 'addEmployee'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    // Habitaciones
    $r->addRoute('GET', '/sires/dashboard/habitaciones', [
        'action' => [RoomController::class, 'showRooms'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR, Rol::GERENTE, Rol::RECEPCIONISTA]
    ]);

    $r->addRoute('GET', '/sires/dashboard/habitaciones/add', [
        'action' => [RoomController::class, 'showNewRoomForm'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('POST', '/sires/dashboard/habitaciones/add/process', [
        'action' => [RoomController::class, 'addRoom'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);
};

