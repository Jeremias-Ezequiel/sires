# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y
[SemVer](https://semver.org/lang/es/).

## [1.1.1] - 2026-11-06

### Seguridad
- Alta, edición y alta por lote de habitaciones validan el token CSRF.
- Activar/desactivar empleados y bloquear, desbloquear, dar de baja, reactivar, limpieza y
  mantenimiento de habitaciones solo se aceptan por POST con token CSRF; un GET responde 405.

### Corregido
- El motivo elegido al bloquear una habitación no se guardaba.

## [1.1.0] - 2026-11-06

### Agregado
- **Inicio** rediseñado como tablero del día: llegadas, salidas, ocupación, disponibles, por limpiar
  y cobrado hoy; llegadas y salidas con check-in / check-out directo (con confirmación) o acceso a
  cobrar; estado de todas las habitaciones con filtro; alertas y ocupación de los próximos 7 días.
- Las alertas del inicio abren la pantalla ya filtrada (reservas, pagos a reembolsar, habitaciones).
- **Configuración** (solo administrador): editar las políticas del negocio desde ahora o programadas,
  con historial de cambios y quién los hizo.
- Calendario de disponibilidad de dos meses al crear y editar reservas; un clic elige entrada y salida.
- Atajo `N` para nueva reserva desde el inicio.

### Cambiado
- Seña mínima, cancelación sin cargo, penalidad y noches retenidas por No-Show se toman según la
  fecha de alta de la reserva: cambiar una política no afecta a reservas ya hechas. Requiere volver
  a correr el script `02` de la base (EVENT `liberacion_no_show`, migración 010).
- Nueva identidad visual: paleta azul de marca, tema oscuro por defecto y tema claro; colores fijos
  por estado de habitación con ícono; tipografía Inter con una escala única de tamaños.
- Se reemplazaron los temas Esmeralda, Grafito, Vino y Océano por Oscuro y Claro.

### Corregido
- Doble ícono de "mostrar contraseña" en Edge.
- Textos por debajo de 12 px y contrastes que no cumplían WCAG AA.

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
