-- 016_reservas_fecha_checkout.down.sql
-- Quita la fecha real de salida. Antes, volver el código a una versión anterior a 1.8.0
-- (el código 1.8.0 escribe esta columna al hacer el check-out).

ALTER TABLE `Reservas` DROP COLUMN IF EXISTS `fecha_checkout`;

DELETE FROM `migraciones` WHERE `nombre` = '016_reservas_fecha_checkout';
