-- 016_reservas_fecha_checkout.up.sql
-- Fecha real de salida del huésped (SIRES 1.8.0).
-- El checkout anticipado finaliza la reserva sin tocar fecha_salida (la pactada, que define
-- el precio cobrado), así que no quedaba registrado el día en que se fue. El Planning dibujaba
-- la barra Finalizada hasta la fecha pactada, encima de la reserva que ocupó después esas noches.
-- La completan ReservaService::checkOut() y checkoutAnticipado(); el Planning corta ahí la barra.
-- Backfill: reservas finalizadas con su check-out registrado en Auditoria (desde 1.3.0).
-- Aditiva: columna nueva nullable.

ALTER TABLE `Reservas`
  ADD COLUMN IF NOT EXISTS `fecha_checkout` date DEFAULT NULL
    COMMENT 'Día en que el huésped se retiró (check-out o checkout anticipado)'
    AFTER `fecha_salida`;

UPDATE `Reservas` r
JOIN (
    SELECT `id_entidad`, MAX(`fecha`) AS `fecha`
    FROM `Auditoria`
    WHERE `entidad` = 'reserva'
      AND `accion` IN ('reserva.checkout', 'reserva.checkout_anticipado')
    GROUP BY `id_entidad`
) a ON a.`id_entidad` = r.`id`
SET r.`fecha_checkout` = DATE(a.`fecha`)
WHERE r.`id_estado_reserva` = 4
  AND r.`fecha_checkout` IS NULL;

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('016_reservas_fecha_checkout');
