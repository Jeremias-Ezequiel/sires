-- 006_drop_events_duplicados.up.sql
-- Elimina dos EVENTs que duplican a crons PHP. Queda una sola implementación por regla:
--   late_checkout_fee => cron/late-checkout-fee.php => PagoService::aplicarLateCheckout()
--   liberar_no_show   => cron/no-show-release.php   => ReservaService::liberarNoShowConPago()
--
-- Por qué:
--   late_checkout_fee: factor 0.5 y hora 11:00 fijos (ignora Politicas). No va a marcar
--     Reservas.late_fee_aplicado (004): cuando el PHP controle por esa columna, cobraría
--     de nuevo lo que cobró el EVENT.
--   liberar_no_show: usa fecha_entrada (libera el mismo día del check-in a las 11:00);
--     el PHP usa fecha_salida (retiene toda la estadía). Además su UPDATE de Habitaciones
--     pasa a Disponible TODA habitación Ocupada que alguna vez tuvo un No-Show, sin filtrar
--     fecha: libera habitaciones con huéspedes alojados cada 30 minutos.
--
-- ANTES DE CORRER: confirmar que los crons están programados en el servidor (crontab -l),
-- si no, nadie cobra late fee ni libera No-Show. Ejemplo:
--   */15 * * * * php /ruta/sires/cron/late-checkout-fee.php >> /ruta/sires/logs/late-checkout-fee.log 2>&1
--   */30 * * * * php /ruta/sires/cron/no-show-release.php  >> /ruta/sires/logs/no-show-release.log  2>&1
--
-- Cambio de comportamiento visible: un No-Show con pago ahora retiene la habitación hasta
-- fecha_salida a la hora de checkout (regla del PHP), no hasta las 11:00 del día de entrada.

DROP EVENT IF EXISTS `late_checkout_fee`;
DROP EVENT IF EXISTS `liberar_no_show`;

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('006_drop_events_duplicados');
