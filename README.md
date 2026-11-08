# SIRES - Sistema de Reservas Hotelero

Gestión de reservas, habitaciones, clientes, empleados y pagos de un hotel.
PHP 8 sin framework (MVC propio) sobre Apache + MySQL/MariaDB.

## Requisitos

- PHP 8.1 o superior con `pdo_mysql`, `mbstring`, `openssl`, `dom` e `iconv` (las dos últimas, para los PDF con dompdf).
- Apache con `mod_rewrite` y `AllowOverride All` (usa el `.htaccess` del proyecto).
- MySQL 8 / MariaDB 10.6 o superior, con `event_scheduler = ON` para los procesos automáticos.
- Composer.

## Instalación

1. Clonar el proyecto en el `DocumentRoot` como carpeta `sires` (la app se sirve en `/sires`):
   ```bash
   cd /var/www/html
   git clone <url-del-repositorio> sires
   cd sires
   ```
2. Instalar dependencias:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Base de datos: crear la base y su usuario, importar `database/sires-demo.sql.gz` y correr los
   scripts `database/scripts/01` a `04`. Paso a paso en [`database/README.md`](database/README.md):
   ```bash
   zcat database/sires-demo.sql.gz | mariadb -u sires -p siresDB
   mariadb -u sires -p siresDB < database/scripts/01_reparar_triggers_y_corte.sql
   mariadb -u sires -p siresDB < database/scripts/02_eventos_late_fee_y_no_show.sql
   mariadb -u root -p         < database/scripts/03_activar_scheduler.sql
   mariadb -u sires -p -t siresDB < database/scripts/04_verificar.sql
   ```
4. Crear `.env` a partir de `.env.example`: base de datos (`DB_*`, el mismo usuario que importó),
   `APP_URL` y SMTP. Sin SMTP la app funciona; los mails quedan pendientes.
5. Dar permiso de escritura a Apache sobre `logs/`:
   ```bash
   sudo chown -R apache:apache logs     # www-data en Debian/Ubuntu
   ```
   En Fedora/RHEL con SELinux, además: `sudo chcon -R -t httpd_sys_rw_content_t logs`,
   `sudo setsebool -P httpd_can_network_connect_db 1` (base por TCP) y
   `sudo setsebool -P httpd_can_network_connect 1` (SMTP).
6. Crontab de los mails (ver "Procesos automáticos").
7. Entrar a `http://<servidor>/sires` con un usuario de demo (contraseña `Sires2026`):

   | Rol | Usuario |
   |---|---|
   | Administrador | `admin@hotel.com` |
   | Recepcionista | `recepcion1@hotel.com` / `recepcion2@hotel.com` |
   | Gerente | `gerencia@hotel.com` |
   | Personal de Limpieza | `limpieza1@hotel.com` |

   El dump trae datos inventados (habitaciones, clientes, políticas y datos del hotel) y ninguna
   reserva. En una instalación real: cambiar las contraseñas de los usuarios de demo (o darlos de
   baja desde Empleados) y completar los datos del hotel en Configuración.
8. Producción: servir por HTTPS (la cookie de sesión pasa a `Secure` y se envía HSTS solos) y no
   exponer versiones del servidor: `ServerTokens Prod` y `ServerSignature Off` en Apache,
   `expose_php = Off` en `php.ini`.

Las carpetas `database/`, `tests/`, `cron/`, `logs/`, `App/` y `vendor/` no se sirven por web (`.htaccess`).
Los cambios de esquema van como migración en `database/migraciones/` (ver su README).

## Procesos automáticos

Los realizan EVENTs de la base de datos (requieren `event_scheduler = ON`):

| EVENT | Frecuencia | Qué hace |
|---|---|---|
| `corte_pagos` | diario 08:00 | Cancela reservas sin pago total dentro del plazo de corte |
| `cargo_late_checkout` | cada 15 min | Cobra el late checkout una única vez |
| `liberacion_no_show` | cada 30 min | Libera habitaciones retenidas por No-Show |

`cron/*.php` son un respaldo por CLI que ejecuta la misma lógica (`php cron/payment-cutoff.php`, etc.).

**Mails al huésped** (confirmación, recordatorio N días antes y comprobante de pago): se encolan en la
tabla `Cola_Mails` y los manda `cron/mails.php`, que **sí necesita el crontab** (un EVENT no puede
mandar mails). Usa el SMTP del `.env` (`SMTP_*`; remitente opcional en `MAIL_FROM` y `MAIL_FROM_NAME`):

```cron
*/5 * * * * php /ruta/a/sires/cron/mails.php >> /ruta/a/sires/logs/mails.log 2>&1
```

Si el cron no corre, los mails quedan pendientes y el inicio del administrador lo avisa
(Auditoría > Procesos automáticos).

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
