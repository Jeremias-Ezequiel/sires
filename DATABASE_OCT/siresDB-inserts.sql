-- =====================================================================
-- SIRES - Hotel Reservation Management System
-- Script: Nuevos estados y configuración del planificador
-- Versión: 0.7.0
-- Fecha: 2026-10-01
-- =====================================================================
-- USO:
--   1. mariadb -u <user> -p siresDB < siresDB-inserts.sql
--   2. Luego ejecutar: source siresDB-eventos.sql
-- =====================================================================
-- NOTA: No modifica estructura de tablas, solo inserta datos nuevos
--       y activa el planificador interno de MariaDB.
-- =====================================================================

-- =====================================================================
-- 0. ACTIVAR PLANIFICADOR DE EVENTOS (MariaDB/MySQL Event Scheduler)
--     Esto permite que los eventos programados funcionen.
--     Es permanente (queda en my.cnf) o se ejecuta una vez.
-- =====================================================================
SET GLOBAL event_scheduler = ON;

-- =====================================================================
-- 1. NUEVOS ESTADOS DE HABITACIÓN
--    - Sucia (5): La habitación fue desocupada y necesita limpieza
--    - Limpiando (6): El personal de limpieza está trabajando en ella
-- =====================================================================
INSERT IGNORE INTO Estados_Habitacion (id, descripcion) VALUES (5, 'Sucia');
INSERT IGNORE INTO Estados_Habitacion (id, descripcion) VALUES (6, 'Limpiando');

-- =====================================================================
-- 2. NUEVO ESTADO DE RESERVA
--    - No-Show con Pago (7): El huésped pagó pero no se presentó.
--      La habitación queda retenida hasta el checkout del día siguiente.
--      NO hay reembolso.
-- =====================================================================
INSERT IGNORE INTO Estados_Reserva (id, descripcion) VALUES (7, 'No-Show con Pago');

-- =====================================================================
-- 3. VERIFICACIÓN
-- =====================================================================
SELECT 'Estados_Habitacion' AS tabla, COUNT(*) AS registros, GROUP_CONCAT(CONCAT(id, '=', descripcion) ORDER BY id SEPARATOR ', ') AS detalle FROM Estados_Habitacion
UNION ALL
SELECT 'Estados_Reserva' AS tabla, COUNT(*) AS registros, GROUP_CONCAT(CONCAT(id, '=', descripcion) ORDER BY id SEPARATOR ', ') AS detalle FROM Estados_Reserva
UNION ALL
SELECT 'Event Scheduler' AS tabla, 0 AS registros, IF(@@event_scheduler = 'ON', 'ACTIVADO', 'DESACTIVADO') AS detalle;