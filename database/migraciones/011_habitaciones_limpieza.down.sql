-- 011_habitaciones_limpieza.down.sql
-- Seguro mientras el código no dependa de las columnas (versiones < 1.1.2).
DROP TRIGGER IF EXISTS `trg_habitacion_limpieza_insert`;
DROP TRIGGER IF EXISTS `trg_habitacion_limpieza_update`;
ALTER TABLE `Habitaciones`
  DROP COLUMN IF EXISTS `limpieza_urgente`,
  DROP COLUMN IF EXISTS `sucia_desde`;
DELETE FROM `migraciones` WHERE `nombre` = '011_habitaciones_limpieza';
