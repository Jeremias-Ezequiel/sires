-- 017_ejecuciones_origen_pedido.down.sql
-- Quita el origen 'pedido'. Antes, volver el código a una versión anterior a 1.9.0
-- (o poner EJECUCION_POR_PEDIDO=0). Las corridas ya registradas por pedido pasan a 'cron'.

UPDATE `Ejecuciones_Automaticas` SET `origen` = 'cron' WHERE `origen` = 'pedido';

ALTER TABLE `Ejecuciones_Automaticas`
  MODIFY COLUMN `origen` enum('event','cron') NOT NULL DEFAULT 'event';

DELETE FROM `migraciones` WHERE `nombre` = '017_ejecuciones_origen_pedido';
