-- 008_politicas_reservas.up.sql
-- Reglas de negocio de reservas decididas el 2026-11-06 (informe de reservas, sección 3).
-- - Estado de pago 6 'Retenido': el hotel se queda con lo cobrado (penalidad por cancelación,
--   No-Show o corte de pagos).
-- - Resumen_Pago.monto_retenido: parte de lo cobrado que retiene el hotel. Al cancelar,
--   monto_total = monto_cobrado y saldo 0 (se respeta chk_resumen_balance); se reembolsa
--   monto_cobrado - monto_retenido.
-- - Transacciones_Pago.chk_transaccion_monto pasa de > 0 a <> 0: un reembolso se registra
--   como transacción negativa (PagoService::procesarReembolso). Con > 0 todo reembolso fallaba.
--   Los pagos siguen validando monto > 0 en PagoService::registrarPago.
-- - Claves nuevas en Configuracion (valores por defecto en App/Config/Politicas.php).
-- Los EVENTs corte_pagos y liberacion_no_show que usan estas reglas se recrean con
-- scripts/01_reparar_triggers_y_corte.sql y scripts/02_eventos_late_fee_y_no_show.sql.

ALTER TABLE `Resumen_Pago`
  ADD COLUMN IF NOT EXISTS `monto_retenido` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `saldo_pendiente`,
  ADD CONSTRAINT IF NOT EXISTS `chk_resumen_retenido` CHECK (`monto_retenido` >= 0 AND `monto_retenido` <= `monto_cobrado`);

ALTER TABLE `Transacciones_Pago`
  DROP CONSTRAINT IF EXISTS `chk_transaccion_monto`,
  ADD CONSTRAINT `chk_transaccion_monto` CHECK (`monto_abonado` <> 0);

INSERT IGNORE INTO `Estados_Pago` (`id`, `descripcion`, `is_active`) VALUES (6, 'Retenido', 1);

INSERT IGNORE INTO `Configuracion` (`clave`, `valor`, `tipo`, `descripcion`, `vigente_desde`) VALUES
('max_noches_reserva',           '30',    'int',  'Máximo de noches por reserva',                                        '2026-11-06 00:00:00'),
('hora_no_show',                 '23:00', 'time', 'Hora del día de entrada desde la que se puede marcar No-Show',         '2026-11-06 00:00:00'),
('sena_minima_pct',              '30',    'int',  'Seña mínima para confirmar, en % del total',                           '2026-11-06 00:00:00'),
('cancelacion_gratis_horas',     '72',    'int',  'Horas de anticipación (a las 00:00 del día de entrada) para cancelar sin cargo', '2026-11-06 00:00:00'),
('cancelacion_penalidad_noches', '1',     'int',  'Noches retenidas al cancelar fuera del plazo sin cargo',               '2026-11-06 00:00:00'),
('noshow_noches_retenidas',      '1',     'int',  'No-Show con pago: noches que se retiene la habitación y se cobran',    '2026-11-06 00:00:00');

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('008_politicas_reservas');
