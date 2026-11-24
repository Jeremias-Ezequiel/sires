-- 005_event_corte_pagos.down.sql
-- Elimina corte_pagos y restaura corte_pagos_48hs tal como está en events/corte_pagos_48hs.original.sql.
DROP EVENT IF EXISTS `corte_pagos`;

DELIMITER //
DROP EVENT IF EXISTS corte_pagos_48hs //
CREATE EVENT corte_pagos_48hs
ON SCHEDULE EVERY 1 DAY
STARTS '2026-10-03 08:00:00'
COMMENT 'Cancela reservas sin pago total 48hs antes del check-in'
DO
BEGIN
    UPDATE Reservas r
    JOIN Resumen_Pago rp ON rp.id_reserva = r.id
    SET r.id_estado_reserva = 3,
        r.observaciones = CONCAT(
            COALESCE(r.observaciones, ''),
            ' | Cancelación automática 48hs antes del check-in. Estado de pago: ',
            CASE rp.id_estado_pago
                WHEN 1 THEN 'Pendiente (sin reembolso)'
                WHEN 2 THEN 'Pago parcial - Pendiente de reembolso'
                ELSE 'Desconocido'
            END,
            '. Fecha: ', NOW()
        ),
        rp.id_estado_pago = CASE
            WHEN rp.id_estado_pago = 2 THEN 5
            ELSE rp.id_estado_pago
        END
    WHERE r.fecha_entrada = DATE_ADD(CURDATE(), INTERVAL 2 DAY)
      AND r.id_estado_reserva IN (1, 2)
      AND rp.id_estado_pago IN (1, 2);

    UPDATE Habitaciones h
    JOIN Reservas r ON r.id_habitacion = h.id
    SET h.id_estado_habitacion = 1
    WHERE r.id_estado_reserva = 3
      AND h.id_estado_habitacion = 2
      AND r.fecha_entrada = DATE_ADD(CURDATE(), INTERVAL 2 DAY);
END //
DELIMITER ;

DELETE FROM `migraciones` WHERE `nombre` = '005_event_corte_pagos';
