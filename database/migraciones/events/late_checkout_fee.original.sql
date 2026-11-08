-- Original de DATABASE_OCT/siresDB-eventos.sql (v0.7.0, 2026-10-01). No editar.
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
