# SIRES - Sistema de Reservas Hotelero

Gestión de reservas, habitaciones, clientes, empleados y pagos de un hotel.
PHP 8 sin framework (MVC propio) sobre Apache + MySQL/MariaDB.

## Requisitos

- PHP 8.1 o superior con `pdo_mysql`, `mbstring` y `openssl`.
- Apache con `mod_rewrite` y `AllowOverride All` (usa el `.htaccess` del proyecto).
- MySQL 8 / MariaDB 10.6 o superior, con `event_scheduler = ON` para los procesos automáticos.
- Composer.

## Instalación

1. Copiar el proyecto en el `DocumentRoot` como carpeta `sires` (la app se sirve en `/sires`).
2. Instalar dependencias:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Crear `.env` a partir de `.env.example` y completar la base de datos, `APP_URL` y SMTP.
4. Base de datos: importar el dump vigente y correr los scripts posteriores (triggers, EVENTs,
   `event_scheduler`). Los scripts SQL no se versionan en este repositorio; se entregan aparte
   junto con su `LEEME.md`. Las migraciones aplicadas quedan registradas en la tabla `migraciones`.
5. Dar permiso de escritura a Apache sobre `logs/` si se usan los cron de respaldo.
6. Producción: servir por HTTPS (la cookie de sesión pasa a `Secure` y se envía HSTS solos) y no
   exponer versiones del servidor: `ServerTokens Prod` y `ServerSignature Off` en Apache,
   `expose_php = Off` en `php.ini`.

## Procesos automáticos

Los realizan EVENTs de la base de datos (requieren `event_scheduler = ON`):

| EVENT | Frecuencia | Qué hace |
|---|---|---|
| `corte_pagos` | diario 08:00 | Cancela reservas sin pago total dentro del plazo de corte |
| `cargo_late_checkout` | cada 15 min | Cobra el late checkout una única vez |
| `liberacion_no_show` | cada 30 min | Libera habitaciones retenidas por No-Show |

`cron/*.php` son un respaldo por CLI que ejecuta la misma lógica (`php cron/payment-cutoff.php`, etc.).

## Configuración del negocio

Las políticas (horas de corte, seña mínima, cancelación, late checkout, No-Show, etc.) viven en la
tabla `Configuracion`, versionadas por `vigente_desde`. Se cambian desde **Configuración** (menú
lateral, solo administrador), desde ahora o programadas para una fecha futura; cada cambio es una fila
nueva y queda el historial. Nunca se modifica ni se borra una fila existente.

Las condiciones que acepta el huésped (seña mínima, cancelación sin cargo, penalidad, noches retenidas
por No-Show, late checkout) se toman según la fecha de alta de la reserva: un cambio no afecta a las
reservas ya hechas. Las operativas (horarios, corte de pagos, máximo de noches) rigen desde el cambio.
`App/Config/Politicas.php` tiene solo los valores por defecto.

## Estilos y temas

- Todos los colores están en `public/css/settings/variables.css` (tokens). Ningún otro CSS ni vista
  usa colores literales.
- Tema oscuro por defecto; `public/css/settings/themes.css` define el tema claro. Se elige desde el
  selector **Tema** del menú lateral (se guarda por navegador).
- Tamaños de letra con la escala `--fs-*` de `variables.css`.
- Después de modificar cualquier CSS fuente correr `bash build-css.sh`, que regenera
  `login.bundle.css` y `pages/dashboard.min.css`.

## Seguridad

- Sesión con cookie `HttpOnly`, `SameSite=Lax` y `Secure` bajo HTTPS; el ID se regenera al iniciar sesión.
- Bajo HTTPS se envía `Strict-Transport-Security`; nunca se envía `X-Powered-By`.
- Toda acción que cambia datos va por POST con token CSRF (un GET a esas rutas responde 405).
- CSRF en todos los formularios POST.
- Bloqueo temporal tras intentos fallidos de login y límite de pedidos de recuperación de contraseña.
- Prepared statements en todas las consultas y escape de salida en las vistas.

## Tests y verificación

Requieren las dependencias de desarrollo (`composer install`, sin `--no-dev`) y PHP 8.2 o superior.

| Comando | Qué hace |
|---|---|
| `composer check` | `php -l` sobre todo el PHP, PHPStan nivel 5 y los tests de unidad. Correrlo antes de cada commit. |
| `composer test` | Tests de unidad (`tests/Unidad`): reglas de `App/Dominio` sin base de datos. |
| `composer phpstan` | Análisis estático (`phpstan.neon.dist`). |
| `composer test:integracion` | Tests de integración (`tests/Integracion`) contra la base real. |

**Unidad.** La lógica pura (precio por noche y descuentos por ocupación, seña mínima, penalidad de
cancelación, late fee, retención por No-Show y lectura de políticas versionadas) vive en `App/Dominio`,
sin PDO. Los Services la usan; los tests le pasan fechas y configuraciones fijas.

**Integración.** Se corre dentro de la VM de testing, con el `.env` que ya tiene el proyecto ahí
(no se crea otro). Cada test abre una transacción y termina con `ROLLBACK`: las transacciones de los
Services pasan a ser `SAVEPOINT`s dentro de ella, así que la base queda igual. Sin `DB_HOST` o sin
`pdo_mysql` los tests se saltean.

**End-to-end (Playwright).** En `tests/e2e`, con su propio `package.json`, contra la VM:

```bash
cd tests/e2e
cp .env.example .env      # URL de la VM y usuarios de prueba por rol
npm ci
npx playwright install chromium
npx playwright test                  # toda la suite
npx playwright test auditoria        # un archivo
```

Corren con un solo worker porque comparten la base de testing. Varios specs crean reservas; los
marcados `@muta` hacen cambios que no se pueden volver atrás y solo corren con `E2E_MUTAR=1`.

## Versionado

[SemVer](https://semver.org/lang/es/). La versión visible está en `App/Config/App.php` y los cambios
en `CHANGELOG.md`.
