-- =====================================================================
-- 04_verificar.sql
-- Diagnóstico de solo lectura. No modifica nada.
-- Cada fila dice OK o FALTA. Ejecutable desde el editor SQL de DBeaver (Alt+X).
-- =====================================================================

SELECT 'Usuario actual' AS chequeo, CURRENT_USER() AS valor, '' AS estado
UNION ALL
SELECT 'Migraciones registradas', COUNT(*),
       IF(COUNT(*) >= 17, 'OK', 'FALTA: deben ser 17 (000-009 y 011-016 vienen en el dump v1.8.0; 010 la registra el 02)')
FROM migraciones
UNION ALL
SELECT 'Tablas de monitoreo y auditoría', COUNT(*),
       IF(COUNT(*) = 2, 'OK', 'FALTA: aplicar migraciones 012 y 013 (o importar el dump v1.8.0)')
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('Ejecuciones_Automaticas', 'Auditoria')
UNION ALL
SELECT 'Comprobantes (tabla y tipo texto)', COUNT(*),
       IF(COUNT(*) = 2, 'OK', 'FALTA: aplicar migración 015 (o importar el dump v1.8.0)')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'Comprobantes' AND COLUMN_NAME = 'numero')
    OR (TABLE_NAME = 'Configuracion' AND COLUMN_NAME = 'tipo' AND COLUMN_TYPE LIKE '%texto%'))
UNION ALL
SELECT 'Fecha real de salida (Reservas.fecha_checkout)', COUNT(*),
       IF(COUNT(*) = 1, 'OK', 'FALTA: aplicar migración 016 (o importar el dump v1.8.0)')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Reservas' AND COLUMN_NAME = 'fecha_checkout'
UNION ALL
SELECT 'Cola de mails (Cola_Mails)', COUNT(*),
       IF(COUNT(*) = 1, 'OK', 'FALTA: aplicar migración 014 (o importar el dump v1.8.0)')
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Cola_Mails'
UNION ALL
SELECT 'EVENTs registran sus corridas', COUNT(*),
       IF(COUNT(*) = 3, 'OK', 'FALTA: correr 01 y 02 (versión con Ejecuciones_Automaticas)')
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
  AND EVENT_NAME IN ('corte_pagos', 'cargo_late_checkout', 'liberacion_no_show')
  AND EVENT_DEFINITION LIKE '%Ejecuciones_Automaticas%'
UNION ALL
SELECT 'Columnas de limpieza en Habitaciones', COUNT(*),
       IF(COUNT(*) = 2, 'OK', 'FALTA: aplicar migración 011')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Habitaciones'
  AND COLUMN_NAME IN ('sucia_desde', 'limpieza_urgente')
UNION ALL
SELECT 'Políticas en Configuracion', COUNT(DISTINCT clave),
       IF(COUNT(DISTINCT clave) = 20, 'OK', 'FALTA: deben ser 20 (aplicar migraciones 008, 014 y 015)')
FROM Configuracion
UNION ALL
SELECT 'Estado de pago Retenido (6)', COUNT(*),
       IF(COUNT(*) = 1, 'OK', 'FALTA: aplicar migración 008')
FROM Estados_Pago WHERE id = 6 AND descripcion = 'Retenido'
UNION ALL
SELECT 'Estado de habitación Retenida (7)', COUNT(*),
       IF(COUNT(*) = 1, 'OK', 'FALTA: aplicar migración 009')
FROM Estados_Habitacion WHERE id = 7 AND descripcion = 'Retenida'
UNION ALL
SELECT 'EVENT liberacion_no_show libera Retenida', COUNT(*),
       IF(COUNT(*) = 1, 'OK', 'FALTA: correr 02 (versión con estado 7)')
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = 'liberacion_no_show'
  AND EVENT_DEFINITION LIKE '%id_estado_habitacion = 7%'
UNION ALL
SELECT 'Columna Resumen_Pago.monto_retenido', COUNT(*),
       IF(COUNT(*) = 1, 'OK', 'FALTA: aplicar migración 008')
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Resumen_Pago' AND COLUMN_NAME = 'monto_retenido'
UNION ALL
SELECT 'Reembolsos permitidos (chk_transaccion_monto <> 0)', COUNT(*),
       IF(COUNT(*) = 1, 'OK', 'FALTA: aplicar migración 008')
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_transaccion_monto'
  AND CHECK_CLAUSE LIKE '%<> 0%'
UNION ALL
SELECT 'Triggers de solapamiento', COUNT(*),
       IF(COUNT(*) = 2, 'OK', 'FALTA: correr 01')
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME IN ('trg_check_solapamiento_insert', 'trg_check_solapamiento_update')
UNION ALL
SELECT 'Triggers de limpieza', COUNT(*),
       IF(COUNT(*) = 2, 'OK', 'FALTA: correr 01 (o la migración 011)')
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME IN ('trg_habitacion_limpieza_insert', 'trg_habitacion_limpieza_update')
UNION ALL
SELECT 'Usuarios de prueba por rol (1, 2, 3, 5)', COUNT(DISTINCT id_rol),
       IF(COUNT(DISTINCT id_rol) = 4, 'OK', 'FALTA: algún rol sin usuario activo')
FROM Usuarios WHERE is_active = 1 AND id_rol IN (1, 2, 3, 5)
UNION ALL
SELECT 'Triggers/EVENTs a nombre de otro usuario', COUNT(*),
       IF(COUNT(*) = 0, 'OK', 'REVISAR: si ese usuario no existe en el servidor, correr 01 y 02')
FROM (SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
      UNION ALL
      SELECT DEFINER FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()) d
WHERE d.DEFINER <> CURRENT_USER()
UNION ALL
SELECT 'EVENTs activos', GROUP_CONCAT(EVENT_NAME ORDER BY EVENT_NAME),
       IF(COUNT(*) = 3, 'OK', 'FALTA: correr 01 y 02')
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
  AND STATUS = 'ENABLED'
  AND EVENT_NAME IN ('corte_pagos', 'cargo_late_checkout', 'liberacion_no_show')
UNION ALL
SELECT 'EVENTs viejos (con bugs)', COUNT(*),
       IF(COUNT(*) = 0, 'OK', 'FALTA: correr 02')
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
  AND EVENT_NAME IN ('corte_pagos_48hs', 'late_checkout_fee', 'liberar_no_show')
UNION ALL
SELECT 'event_scheduler', @@GLOBAL.event_scheduler,
       IF(@@GLOBAL.event_scheduler = 'ON', 'OK', 'FALTA: correr 03 (requiere SUPER)')
UNION ALL
SELECT 'Zona horaria del servidor', CONCAT(@@GLOBAL.time_zone, ' / ', @@system_time_zone, ' / ', NOW()),
       'REVISAR: NOW() debe ser la hora de Buenos Aires';

-- Última ejecución de cada EVENT (NULL = todavía no corrió).
SELECT EVENT_NAME, DEFINER, STATUS, LAST_EXECUTED, INTERVAL_VALUE, INTERVAL_FIELD
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
ORDER BY EVENT_NAME;

-- Últimas corridas registradas por los EVENTs o los cron (vacío = todavía no corrió ninguna).
-- Si falta la tabla (migración 012) muestra un aviso en vez de cortar el script.
SET @sires_q = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Ejecuciones_Automaticas'),
    'SELECT proceso, origen, inicio, fin, filas_afectadas, ok, error FROM Ejecuciones_Automaticas ORDER BY inicio DESC LIMIT 10',
    'SELECT ''FALTA la tabla Ejecuciones_Automaticas: importar el dump v1.8.0 o aplicar la migración 012'' AS aviso');
PREPARE sires_stmt FROM @sires_q;
EXECUTE sires_stmt;
DEALLOCATE PREPARE sires_stmt;
