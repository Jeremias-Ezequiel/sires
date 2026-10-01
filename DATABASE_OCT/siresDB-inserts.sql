-- =====================================================================
-- SIRES - Hotel Reservation Management System
-- Script: Nuevos estados para flujo de Limpieza y No-Show
-- Versión: 0.7.0
-- Fecha: 2026-10-01
-- =====================================================================
-- NOTA: No modifica estructura de tablas, solo inserta datos nuevos.
-- Ejecutar con: mariadb -u <user> -p siresDB < siresDB-inserts.sql
-- =====================================================================

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