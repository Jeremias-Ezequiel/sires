# SIRES - Sistema de Reservas Hotelero

Gestión de reservas de hotel. PHP 8+ sin framework, arquitectura MVC propia sobre Apache + MySQL/MariaDB.

## Stack

- PHP 8+ con `declare(strict_types=1)` en casi todos los archivos.
- Apache con `.htaccess` (rewrites todo a `index.php`; bloquea `.env`, `App/`, `vendor/` y `composer.*`).
- MySQL/MariaDB vía PDO (prepared statements, excepciones, `FETCH_ASSOC`).
- Composer solo para autoload (`App\` → `App/`) + helpers y dependencias: `vlucas/phpdotenv`, `nikic/fast-route`, `phpmailer/phpmailer`.
- Deploy en subdirectorio `/sires`. No hay tests ni linter configurados; verificar con `php -l` sobre los archivos modificados.

## Estructura y flujo

- `index.php` = front controller. Rutas definidas en `App/routes.php` (FastRoute).
- `App/Controllers/*` reciben `$vars` y renderizan vistas `.phtml` de `App/views/` con `require`.
- `App/Models/*` extienden `App\Models\Model` (PDO singleton vía `App\Config\Database`). Constantes de negocio como consts de clase (estados de reserva/habitación, roles).
- `App/Services/*` = lógica de negocio compleja: transacciones, bloqueos `FOR UPDATE`, validaciones.
- `App/Middleware/*` = clases con métodos **estáticos** invocadas desde la definición de ruta.
- `App/Core/ErrorHandler.php` registrado en `index.php`; loguea con prefijo `[SIRES ...]`. Rutas `/api/*` responden JSON.
- `App/Helpers/functions.php` = helpers globales cargados por Composer: `url()`, `asset()`, `csrf_token()`, `csrf_field()`, `csrf_check()`.
- Vistas: `App/views/partials/` (head/footer), `App/views/dashboard/layout.phtml`.

## Convenciones de rutas (`App/routes.php`)

```php
$r->addRoute('GET', '/dashboard/employees', [
    'action'     => [EmployeeController::class, 'showEmployees'],
    'middlewares'=> [[AuthMiddleware::class, 'verifyLogin']],
    'roles'      => [Rol::ADMINISTRADOR],
]);
```

- Roles: consts de `App\Models\Rol` — ADMINISTRADOR=1, RECEPCIONISTA=2, GERENTE=3, LIMPIEZA=5.
- GET simple = solo `verifyLogin`; mutaciones de estado (cancel/confirm/checkin/noshow/refund, etc.) = `verifyLogin` + `CsrfMiddleware::verify`.

## Seguridad (obligatorio)

- Toda ruta protegida: `AuthMiddleware::verifyLogin` (+ `authorize` por `roles`).
- Formularios POST: incluir `csrf_field()` en la vista y `CsrfMiddleware::verify` en la ruta (o `csrf_check()` si es un POST interno).
- Siempre prepared statements; escapar salida de datos de BD en las vistas.
- `.env` no se versiona (ver `.env.example`): `DB_HOST/PORT/NAME/USER/PASS`, `API_RELOJ_URL/USER/PASS`.

## URLs

- Base: `/sires/index.php?route=...`. Usar siempre `url()` / `asset()` / `openForm()` de `App\Helpers\UrlHelper`; nunca hardcodear rutas.
- Parámetros extra se pasan como query string (`url('/x', ...)` → `?route=...&id=...`).

## Cron

- `cron/*.php` = scripts CLI autónomos con bootstrap propio (`autoload.php` + dotenv). Loguean en `logs/` (gitignored).
- `cron/payment-cutoff.php` está **deprecado**: reemplazado por el MySQL EVENT `corte_pagos_48hs`. Lógica principal en `PagoService::ejecutarCortePagos()`.
- Cuidado al tocar flujos automáticos: muchos estados se mueven vía cron (no-show release, late checkout fee).

## Idioma

- Todo el código, comentarios y mensajes al usuario están en **español**. Mantenerlo.