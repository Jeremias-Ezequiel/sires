-- 012_ejecuciones_automaticas.down.sql
-- Antes de bajarla, volver a crear los EVENTs con la versión anterior de los scripts 01 y 02
-- (los actuales insertan en esta tabla y fallarían).
DROP TABLE IF EXISTS `Ejecuciones_Automaticas`;
DELETE FROM `migraciones` WHERE `nombre` = '012_ejecuciones_automaticas';
