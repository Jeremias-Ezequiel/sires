# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y
[SemVer](https://semver.org/lang/es/).

## [1.8.0] - 2026-11-07

### Agregado
- **Paginación de Habitaciones** (20 por página, conserva los filtros).
- El paginador de todos los listados muestra la primera, la última y las páginas cercanas a la actual,
  con "…" entre saltos, en lugar de un botón por página.
- El historial del detalle de reserva y de habitación enlaza a *Auditoría* filtrada cuando llega a su
  tope de 30 acciones.

### Cambiado
- **Menú lateral:** Configuración, Reloj Oficial, Tema y Cerrar sesión pasan a un pie separado de la
  navegación, con el selector de tema al final y la versión. El menú queda fijo al alto de la ventana.
- **Observaciones de la reserva:** cada mensaje automático (upgrade, recálculo, late checkout, corte de
  pagos, No-Show, salida anticipada) va en su propia línea como `- Mensaje`, y el detalle respeta los
  saltos de línea. Las reservas anteriores se muestran con el formato nuevo sin migrar datos.
- **Alta y edición de reserva:** cliente y habitación van uno al lado del otro; debajo, la agenda de
  disponibilidad a todo el ancho y las fechas de entrada y salida en una fila; cantidad de huéspedes y
  canal de origen comparten la fila siguiente.
- **Agenda de disponibilidad:** cada mes es un panel propio y los días libres se ven como celdas, para
  distinguir los dos meses y los días de los huecos.
- La agenda muestra las reservas de la habitación con el color de su estado, como el Planning, y las
  lista debajo (cliente, fechas, estado y enlace al detalle). Al elegir la salida solo se puede llegar
  hasta el día en que entra la reserva siguiente; las noches ocupadas no se pueden elegir.
- El buscador de habitaciones lista también las ocupadas en las fechas cargadas (en su orden, marcadas),
  para poder ver su agenda y reservar los días libres. La reserva no se guarda hasta elegir fechas libres.
- **Check-out:** con el huésped en casa, el botón *Check-out* aparece desde el día de salida y
  *Checkout anticipado* solo antes; en el listado de reservas y en el detalle. Una reserva confirmada sin
  check-in ya no se puede finalizar (corresponde No-Show). El servidor aplica la misma regla.
- Base de datos: **volver a correr los scripts 01 y 02** (EVENTs `corte_pagos`, `cargo_late_checkout` y
  `liberacion_no_show` con el formato nuevo de observaciones). No hay migración nueva.

### Corregido
- **Seguridad:** el token de recuperación de contraseña se guarda hasheado; dar de baja a un empleado o
  cambiarle el rol rige al instante aunque tenga la sesión abierta; *Reloj Oficial* exige iniciar sesión.
- Las advertencias del detalle de pago (reembolso pendiente, reserva cancelada, pagada) ya no desaparecen
  a los 3 segundos.
- Las observaciones de la reserva ya no se escapan dos veces (`&` se veía como `&amp;`).
- "Habitaciones" se marca como activa en el menú.
- La agenda del alta y la edición de reservas no mostraba como ocupadas las estadías en curso (En casa)
  ni las habitaciones retenidas por No-Show; el servidor sí las rechazaba al guardar.

## [1.7.0] - 2026-11-06

### Agregado
- **Comprobantes PDF:** recibo de cada pago, comprobante de cada reembolso y resumen de la reserva, con
  numeración correlativa propia por tipo (ej. `0001-00000012`). No son facturas fiscales.
- Se descargan desde el detalle del pago y de la reserva; el mail del pago adjunta el recibo.
- *Configuración > Datos del hotel*: nombre, razón social, CUIT, condición frente al IVA, domicilio,
  teléfono, email y punto de venta.

### Cambiado
- Base de datos: migración `015_comprobantes` (tabla `Comprobantes`, tipo `texto` en `Configuracion` y
  claves del hotel), incluida en el dump `v1.7.0`. Nueva dependencia `dompdf/dompdf` (requiere `ext-iconv`).

## [1.6.0] - 2026-11-06

### Agregado
- **Mails al huésped:** confirmación al crear la reserva, recordatorio antes de la entrada (los días se
  configuran en *Configuración > Mails al huésped*, 3 por defecto) y comprobante de cada pago.
- Los mails se encolan y los manda `cron/mails.php` cada 5 minutos, con reintentos (hasta 5). La
  confirmación y el recordatorio de una reserva cancelada no se mandan.
- El detalle de la reserva muestra los mails enviados, pendientes y con error.
- *Procesos automáticos* incluye el envío de mails y avisa si hay mails esperando y el cron no corre.

### Cambiado
- Base de datos: migración `014_cola_mails` (tabla `Cola_Mails` y clave `recordatorio_dias_antes`),
  incluida en el dump `v1.6.0`. Requiere una línea de crontab para `cron/mails.php` (ver README).

## [1.5.0] - 2026-11-06

### Agregado
- **Planning de ocupación** (menú *Planning*): habitaciones en filas y 14 o 30 días en columnas, con
  navegación por período y por fecha. Cada reserva es una barra con ícono y texto según su estado
  (pendiente, confirmada, en casa, sale hoy, No-Show con habitación retenida, finalizada).
- Clic en una reserva abre su detalle; clic en un día libre abre una reserva nueva con la habitación
  y la fecha de entrada ya cargadas.

## [1.4.0] - 2026-11-06

### Agregado
- **Tests de unidad** (PHPUnit) de las reglas de negocio: precio y descuentos por ocupación, seña
  mínima, penalidad de cancelación, late fee, retención por No-Show y políticas según la fecha de la reserva.
- **Tests de integración** contra la base de testing: cada test termina con `ROLLBACK` y no deja datos.
- **Tests end-to-end** (Playwright) dentro del repositorio, en `tests/e2e`, con specs nuevos de
  limpieza, auditoría y procesos automáticos.
- **PHPStan** nivel 5 y `composer check` (sintaxis + análisis estático + tests de unidad).

### Cambiado
- Las reglas de cálculo pasan a `App/Dominio`, sin acceso a la base; los Services las usan sin cambiar
  su comportamiento.

## [1.3.0] - 2026-11-06

### Agregado
- **Auditoría** (administración y gerencia): quién hizo qué y cuándo en reservas, pagos, habitaciones,
  empleados y configuración, con los valores de antes y después. Filtros por usuario, tipo y fechas.
- **Historial** en el detalle de cada reserva y habitación.
- **Monitoreo de procesos automáticos:** cada corrida del corte de pagos, el late checkout y la
  liberación de No-Show queda registrada; el inicio del administrador avisa si un proceso no corre,
  falla o si el programador de tareas de la base está apagado. Pestaña *Procesos automáticos*.
- `cron/verificar_events.php` para conectar un monitor externo.

### Cambiado
- Base de datos: dump `v1.3.0` (migraciones 012 `Ejecuciones_Automaticas` y 013 `Auditoria`). El dump ya
  no incluye los EVENTs: los crean los scripts 01 y 02, que son obligatorios después de importarlo.

### Corregido
- Errores al operar reservas y pagos mostraban "There is no active transaction" en vez del mensaje real.

## [1.2.0] - 2026-11-06

### Agregado
- **Limpieza (housekeeping):** lista de habitaciones en limpieza y por limpiar en orden sugerido
  (urgente, llega huésped hoy, mañana, sin llegada; luego la más antigua y por piso), con el motivo
  y cuánto lleva sucia. Pensada para el celular y se actualiza sola.
- Recepción, gerencia y administración pueden marcar una limpieza como **urgente**.
- El rol Limpieza puede iniciar y terminar limpiezas; su inicio es la lista de housekeeping.
- En pantallas de menos de 600 px el menú lateral pasa a una barra superior.

### Cambiado
- Requiere la migración `011_habitaciones_limpieza` (columnas `sucia_desde` y `limpieza_urgente`
  en `Habitaciones` y sus triggers).

### Seguridad
- Sin `X-Powered-By`; `Strict-Transport-Security` bajo HTTPS.
- Un token CSRF inválido sin página de origen vuelve al inicio; en `/api/*` responde JSON 419.

### Corregido
- Errores de habitaciones y limpieza mostraban "There is no active transaction" en vez del mensaje real.
- Reactivar una habitación desde su detalle usa el diálogo del sistema en lugar del `confirm()` del navegador.

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
