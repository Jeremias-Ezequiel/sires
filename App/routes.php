<?php

declare(strict_types=1);

use FastRoute\RouteCollector;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\EmployeeController;
use App\Controllers\RecoveryController;
use App\Controllers\RoomController;
use App\Controllers\ApiController;
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

    // Pantalla de Login explícita
    $r->addRoute('GET', '/login', [
        'action' => [AuthController::class, 'showLogin'],
    ]);

    // Procesamiento del formulario de Login (POST)
    $r->addRoute('POST', '/login/process', [
        'action' => [AuthController::class, 'login']
    ]);

    // Cerrar sesión
    $r->addRoute('GET', '/logout', [
        'action' => [AuthController::class, 'logout']
    ]);

    // 🔑 SISTEMA DE RECUPERACIÓN DE CONTRASEÑA

    // Pantalla 3: Formulario para ingresar el mail
    $r->addRoute('GET', '/password/recovery', [
        'action' => [RecoveryController::class, 'showRecoveryForm']
    ]);

    // Procesamiento de Pantalla 3: Envío de mail real con PHPMailer
    $r->addRoute('POST', '/password/recovery/process', [
        'action' => [RecoveryController::class, 'processRecovery']
    ]);

    // Pantalla 4: Formulario para ingresar la nueva contraseña usando el token dinámico
    $r->addRoute('GET', '/password/reset/{token}', [
        'action' => [RecoveryController::class, 'showResetForm']
    ]);

    // Procesamiento de Pantalla 4: Validación y cambio de clave en la BD
    $r->addRoute('POST', '/password/reset/process', [
        'action' => [RecoveryController::class, 'processReset']
    ]);

    // Botones en mantenimiento
    $r->addRoute('GET', '/maintenance', [
        'middlewares' => [[MaintenanceMiddleware::class, 'check']]
    ]);

    // =====================================================================
    // 🌍 API PÚBLICA (Datos geográficos para cascading dropdowns)
    // =====================================================================

    $r->addRoute('GET', '/api/provincias', [
        'action' => [ApiController::class, 'getProvinces']
    ]);

    $r->addRoute('GET', '/api/localidades', [
        'action' => [ApiController::class, 'getCities']
    ]);

    // =====================================================================
    // 🔒 RUTAS PROTEGIDAS (Pasan por la tubería de Middlewares)
    // =====================================================================

    // Panel Principal - Dashboard de SIRES
    $r->addRoute('GET', '/dashboard', [
        'action' => [DashboardController::class, 'showHome'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']]
    ]);

    // Employees
    $r->addRoute('GET', '/dashboard/employees', [
        'action' => [EmployeeController::class, 'showEmployees'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('GET', '/dashboard/employees/add', [
        'action' => [EmployeeController::class, 'showNewEmployeeForm'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('POST', '/dashboard/employees/add/process', [
        'action' => [EmployeeController::class, 'addEmployee'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);
    // Restablecer contraseña de empleados desde el Dashboard
    $r->addRoute('GET', '/sires/dashboard/employees/reset-password', [
        'action' => [EmployeeController::class, 'showResetPasswordForm'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('POST', '/sires/dashboard/employees/reset-password/process', [
        'action' => [EmployeeController::class, 'resetPassword'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('GET', '/dashboard/employees/edit', [
        'action' => [EmployeeController::class, 'showEditEmployeeForm'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('POST', '/dashboard/employees/edit/process', [
        'action' => [EmployeeController::class, 'editEmployee'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('GET', '/dashboard/employees/deactivate', [
        'action' => [EmployeeController::class, 'deactivateEmployee'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('GET', '/dashboard/employees/activate', [
        'action' => [EmployeeController::class, 'activateEmployee'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    // Habitaciones
    $r->addRoute('GET', '/dashboard/rooms', [
        'action' => [RoomController::class, 'showRooms'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR, Rol::GERENTE, Rol::RECEPCIONISTA]
    ]);

    $r->addRoute('GET', '/dashboard/rooms/add', [
        'action' => [RoomController::class, 'showNewRoomForm'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);

    $r->addRoute('POST', '/dashboard/rooms/add/process', [
        'action' => [RoomController::class, 'addRoom'],
        'middlewares' => [[AuthMiddleware::class, 'verifyLogin']],
        'roles' => [Rol::ADMINISTRADOR]
    ]);
};

