# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y
[SemVer](https://semver.org/lang/es/).

## [1.0.0] - 2026-11-06

Primera versión estable.

### Agregado
- Reglas de negocio centralizadas en la tabla `Configuracion` (versionada por `vigente_desde`) y
  leídas con `ConfiguracionService`: corte de pago, seña mínima, máximo de noches, política de
  cancelación, late checkout, No-Show, expiración del token de recuperación.
- Procesos automáticos como EVENTs de la base (`corte_pagos`, `cargo_late_checkout`,
  `liberacion_no_show`) con respaldo por cron.
- Retención de montos (`Resumen_Pago.monto_retenido`) al cancelar, por No-Show o en el corte de pagos;
  reembolsos como transacción negativa.
- Estado de habitación **Retenida** para No-Show con pago.
- Precio pactado por noche en la reserva y recálculo de precio por POST con CSRF.
- Temas de color (`themes.css`) y selector de tema en el menú lateral.
- `README.md`, `CHANGELOG.md` y `.env.example` completo (`DB_PORT`, `APP_URL`, `SMTP_*`).

### Cambiado
- Colores centralizados en tokens CSS (`variables.css`); sin colores literales en CSS ni vistas.
- Estilos inline de las vistas reemplazados por clases (`utilities.css`); paginación unificada en
  `partials/pagination.phtml`.
- `build-css.sh` genera también `dashboard.min.css`; los CSS se versionan por fecha de modificación.

### Seguridad
- Cookie de sesión `HttpOnly`, `SameSite=Lax`, `Secure` bajo HTTPS y `use_strict_mode`.
- Regeneración del ID de sesión y del token CSRF al iniciar sesión.
- Bloqueo temporal tras 5 intentos fallidos de login por email+IP (20 por IP).
- Recuperación de contraseña: CSRF, respuesta única exista o no el correo, límite por IP, enlace
  armado con `APP_URL` y sin exponer errores de SMTP.
- Escape de nombres insertados con `innerHTML` en los modales de confirmación.
- Headers `X-Content-Type-Options`, `X-Frame-Options` y `Referrer-Policy`.

### Corregido
- Token CSRF inválido devolvía error 500 (`use Exception` faltante).
- La paginación y varios textos usaban colores de tema claro sobre fondo oscuro.
- `.badge--warning` y `.alert--info` se usaban sin estilo definido.

### Eliminado
- Vistas sin uso: `dashboard/dashboard.phtml`, `auth/register.phtml` y `pages/register.css`.
- `ResumenPago::reembolsarPorReserva()` sin uso.
