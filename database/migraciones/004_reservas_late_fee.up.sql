-- 004_reservas_late_fee.up.sql
-- Problema: la idempotencia del late fee busca con LIKE el texto
-- 'Late Checkout Fee Aplicado' en observaciones. Si alguien edita el texto, se cobra de nuevo.
-- Solución: columna booleana dedicada. Aditivo: DEFAULT 0, el código actual la ignora.

ALTER TABLE `Reservas`
  ADD COLUMN IF NOT EXISTS `late_fee_aplicado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `observaciones`;

UPDATE `Reservas`
SET late_fee_aplicado = 1
WHERE late_fee_aplicado = 0
  AND observaciones LIKE '%Late Checkout Fee Aplicado%';

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('004_reservas_late_fee');
