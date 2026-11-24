-- 003_reservas_precio_pactado.down.sql
ALTER TABLE `Reservas` DROP CONSTRAINT IF EXISTS `chk_reserva_precio_pactado`;
ALTER TABLE `Reservas` DROP COLUMN IF EXISTS `precio_noche_pactado`;
DELETE FROM `migraciones` WHERE `nombre` = '003_reservas_precio_pactado';
