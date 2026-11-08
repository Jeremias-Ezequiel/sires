-- 008_politicas_reservas.down.sql
-- Antes: volver a crear los EVENTs anteriores (versión de scripts/01 y 02 previa al 2026-11-06)
-- y desplegar el código anterior. No se puede borrar el estado 6 si hay resúmenes en ese estado.
ALTER TABLE `Resumen_Pago`
  DROP CONSTRAINT IF EXISTS `chk_resumen_retenido`,
  DROP COLUMN IF EXISTS `monto_retenido`;

-- Falla si ya hay reembolsos registrados (transacciones negativas).
ALTER TABLE `Transacciones_Pago`
  DROP CONSTRAINT IF EXISTS `chk_transaccion_monto`,
  ADD CONSTRAINT `chk_transaccion_monto` CHECK (`monto_abonado` > 0);

DELETE FROM `Configuracion`
WHERE `clave` IN ('max_noches_reserva', 'hora_no_show', 'sena_minima_pct',
                  'cancelacion_gratis_horas', 'cancelacion_penalidad_noches', 'noshow_noches_retenidas');
DELETE FROM `Estados_Pago`
WHERE `id` = 6 AND NOT EXISTS (SELECT 1 FROM `Resumen_Pago` WHERE `id_estado_pago` = 6);
DELETE FROM `migraciones` WHERE `nombre` = '008_politicas_reservas';
