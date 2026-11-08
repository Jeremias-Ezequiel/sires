-- 006_drop_events_duplicados.down.sql
-- Restaura los EVENTs originales (copias de events/*.original.sql).
-- Si los crons PHP siguen activos, quedan otra vez dos implementaciones corriendo.

DELIMITER //
DROP EVENT IF EXISTS late_checkout_fee //
CREATE EVENT late_checkout_fee
ON SCHEDULE EVERY 15 MINUTE
STARTS '2026-10-03 00:00:00'
COMMENT 'Aplica cargo de media noche extra por checkout tardío'
DO
BEGIN
    UPDATE Resumen_Pago rp
    JOIN Reservas r ON rp.id_reserva = r.id
    JOIN Habitaciones h ON h.id = r.id_habitacion
    SET rp.monto_total       = rp.monto_total + ROUND(h.precio_noche_base / 2, 2),
        rp.saldo_pendiente   = rp.saldo_pendiente + ROUND(h.precio_noche_base / 2, 2),
        r.observaciones       = CONCAT(
            COALESCE(r.observaciones, ''),
            ' | Late Checkout Fee Aplicado: $',
            ROUND(h.precio_noche_base / 2, 2),
            ' por salida después de las 11:00 AM. Fecha: ', NOW()
        )
    WHERE r.id_estado_reserva = 5
      AND r.fecha_salida = CURDATE()
      AND CURTIME() >= '11:00:00'
      AND (r.observaciones IS NULL OR r.observaciones NOT LIKE '%Late Checkout Fee%');
END //
DELIMITER ;

DELIMITER //
DROP EVENT IF EXISTS liberar_no_show //
CREATE EVENT liberar_no_show
ON SCHEDULE EVERY 30 MINUTE
STARTS '2026-10-03 00:00:00'
COMMENT 'Libera habitaciones retenidas por No-Show con pago'
DO
BEGIN
    UPDATE Reservas r
    SET r.id_estado_reserva = 6,
        r.observaciones = CONCAT(
            COALESCE(r.observaciones, ''),
            ' | Liberación automática tras retención por No-Show con pago. Fecha: ', NOW()
        )
    WHERE r.id_estado_reserva = 7
      AND (r.fecha_entrada < CURDATE()
           OR (r.fecha_entrada = CURDATE() AND CURTIME() >= '11:00:00'));

    UPDATE Habitaciones h
    JOIN Reservas r ON r.id_habitacion = h.id
    SET h.id_estado_habitacion = 1
    WHERE r.id_estado_reserva = 6
      AND h.id_estado_habitacion = 2;
END //
DELIMITER ;

DELETE FROM `migraciones` WHERE `nombre` = '006_drop_events_duplicados';
