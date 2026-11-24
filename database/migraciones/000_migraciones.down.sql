-- 000_migraciones.down.sql
-- Revertir solo después de revertir todas las demás migraciones.
DROP TABLE IF EXISTS `migraciones`;
