-- 013_auditoria.down.sql
-- Seguro solo con código < 1.3.0 (el actual escribe en Auditoria en cada operación).
DROP TABLE IF EXISTS `Auditoria`;
DELETE FROM `migraciones` WHERE `nombre` = '013_auditoria';
