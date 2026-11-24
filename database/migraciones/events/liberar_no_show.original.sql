-- Original de DATABASE_OCT/siresDB-eventos.sql (v0.7.0, 2026-10-01). No editar.
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
