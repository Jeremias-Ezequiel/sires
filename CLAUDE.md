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
- `App/Helpers/functions.php` = helpers globales cargados por Composer: `url()`, `asset()`, `asset_v()` (asset con `?v=filemtime`, usar para CSS/JS), `csrf_token()`, `csrf_field()`, `csrf_check()`.
- Vistas: `App/views/partials/` (head/footer, `pagination.phtml`, `theme-switcher.phtml`), `App/views/dashboard/layout.phtml`.

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
- Siempre prepared statements; escapar salida de datos de BD en las vistas. En JS, todo dato insertado con `innerHTML` pasa por `escHtml()` (global, definido en `partials/head.phtml`).
- Login y recuperación de contraseña limitados con `App\Services\LimiteIntentosService` (contadores en archivos del directorio temporal). La sesión se configura en `index.php` (cookie HttpOnly/SameSite, `session_regenerate_id` al loguear).
- `.env` no se versiona (ver `.env.example`): `DB_HOST/PORT/NAME/USER/PASS`, `APP_URL` (base del link del mail de recuperación), `SMTP_HOST/PORT/USER/PASS`, `API_RELOJ_URL/USER/PASS`.

## URLs

- Base: `/sires/index.php?route=...`. Usar siempre `url()` / `asset()` / `openForm()` de `App\Helpers\UrlHelper`; nunca hardcodear rutas.
- Parámetros extra se pasan como query string (`url('/x', ...)` → `?route=...&id=...`).

## Reglas de negocio

- Única fuente: tabla `Configuracion` (versionada por `vigente_desde`), leída con `App\Services\ConfiguracionService` (corte de pago, hora de checkout, factor late checkout, descuentos por ocupación, expiración de token, hora de No-Show, máximo de noches, seña mínima, política de cancelación, noches retenidas por No-Show). `App/Config/Politicas.php` = valores por defecto si falta la tabla o la clave, y `ZONA_HORARIA`. Nunca hardcodear estos valores.
- Cambiar una política = INSERT en `Configuracion` con un `vigente_desde` nuevo; nunca UPDATE ni DELETE (pantalla `/dashboard/settings`, solo administrador). Las condiciones que acepta el huésped se leen a `Reservas.fecha_alta` (pasar la fecha a `ConfiguracionService`): seña mínima, cancelación sin cargo, penalidad de cancelación, noches retenidas por No-Show y factor de late checkout. Las operativas (horarios, corte de pagos, máximo de noches, token) rigen desde la fecha actual.
- El JS de las vistas recibe los valores con `json_encode(ConfiguracionService::...)`. Cálculo de precio compartido en `public/js/reserva-precio.js`.
- `Reservas.precio_noche_pactado` guarda el precio efectivo por noche (al crear/editar/recalcular); `Reservas.late_fee_aplicado` es la marca de idempotencia del late checkout.
- Lo que el hotel se queda al cancelar, por No-Show o en el corte de pagos va en `Resumen_Pago.monto_retenido` (vía `PagoService::aplicarRetencion()`): queda `A Reembolsar` si se cobró más, o `Retenido` (estado de pago 6) si no. Los reembolsos se registran como transacción negativa.
- Constantes de estado: los valores viven en las clases catálogo (`EstadoReserva`, `EstadoHabitacion`, `EstadoPago`); `Reserva::ESTADO_*`, `Habitacion::ESTADO_*`, etc. son alias.
- Auditoría (tabla `Auditoria`): toda acción nueva que cambie datos se registra con `AuditoriaService` dentro de la misma transacción: `$antes = $this->auditoria->foto('reserva', $id)` antes de modificar y `$this->auditoria->registrar('reserva.accion', 'reserva', $id, 'Descripción', $antes)` antes del `commit()`. Nunca guardar contraseñas ni tokens.
- Lógica de negocio en `Services/`, no en controllers ni crons. Controllers nuevos: `extends Controller` (`redirect()`, `flash()`, `recordarInputs()`). La sesión se inicia en `index.php`.

## Estilos (CSS)

- Colores SOLO como tokens en `public/css/settings/variables.css` (paleta + derivados con `color-mix()` + `--estado-*`). Ningún otro CSS ni vista usa hex/rgba literales ni `style="color..."`: usar `var(--color-...)` o una clase.
- Temas en `public/css/settings/themes.css` (`:root[data-theme="..."]`, solo redefinen la paleta); listado en `partials/theme-switcher.phtml`.
- Clases utilitarias y piezas chicas en `public/css/components/utilities.css`; evitar `style=""` salvo `display: none` que maneja JS.
- `pages/dashboard.css` carga después de `global.css`: si una utilidad choca con una regla del dashboard, subir especificidad.
- Después de tocar cualquier CSS fuente: `bash build-css.sh` (regenera `login.bundle.css` y `pages/dashboard.min.css`, y avisa si hay colores literales).

## Base de datos

- Los scripts SQL (migraciones y EVENTs) no se versionan en este repo. Las migraciones aplicadas quedan registradas en la tabla `migraciones`.
- Triggers: `trg_check_solapamiento_*` (Reservas) y `trg_habitacion_limpieza_*` (Habitaciones, migración 011). Este último setea `sucia_desde` al pasar a Sucia y limpia `sucia_desde`/`limpieza_urgente` al salir de Sucia/Limpiando: no setearlos a mano en los Services.

## Automatismos (EVENTs y cron)

- Los flujos automáticos son MySQL EVENTs (requieren `event_scheduler = ON`): `corte_pagos` (diario 08:00), `cargo_late_checkout` (15 min), `liberacion_no_show` (30 min). Leen `Configuracion` y replican la lógica de los Services.
- `cron/*.php` quedan como **respaldo**: cargan `cron/bootstrap.php` (autoload, dotenv, zona horaria, solo CLI) y delegan en `PagoService::ejecutarCortePagos()`, `PagoService::aplicarLateCheckout()` y `ReservaService::liberarNoShowConPago()`. Loguean en `logs/` (gitignored).
- Si se cambia la lógica de uno de esos métodos, actualizar también el EVENT equivalente en la BD (y viceversa).
- Monitoreo: cada EVENT registra su corrida en `Ejecuciones_Automaticas` (INSERT al empezar, UPDATE al terminar o en el EXIT HANDLER); los cron lo hacen con `MonitoreoService::ejecutarConRegistro()`. Un proceso automático nuevo debe sumarse a `MonitoreoService::PROCESOS`. El dump no trae EVENTs: viven en los scripts 01 y 02.

## Idioma

- Todo el código, comentarios y mensajes al usuario están en **español**. Mantenerlo.