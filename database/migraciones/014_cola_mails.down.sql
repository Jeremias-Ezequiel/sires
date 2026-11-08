-- 014_cola_mails.down.sql
-- Seguro solo con código < 1.6.0 (el actual encola mails al crear reservas y registrar pagos).
DROP TABLE IF EXISTS `Cola_Mails`;
DELETE FROM `Configuracion` WHERE `clave` = 'recordatorio_dias_antes';
DELETE FROM `migraciones` WHERE `nombre` = '014_cola_mails';
