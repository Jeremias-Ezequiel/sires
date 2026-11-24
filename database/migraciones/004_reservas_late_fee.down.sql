-- 004_reservas_late_fee.down.sql
-- Seguro solo mientras el código siga escribiendo 'Late Checkout Fee Aplicado' en observaciones.
ALTER TABLE `Reservas` DROP COLUMN IF EXISTS `late_fee_aplicado`;
DELETE FROM `migraciones` WHERE `nombre` = '004_reservas_late_fee';
