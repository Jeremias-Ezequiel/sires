-- 005_event_corte_pagos.up.sql
-- Depende de 001 (Configuracion) y 002 (triggers corregidos).
--
-- Reemplaza el EVENT corte_pagos_48hs (original en events/corte_pagos_48hs.original.sql).
-- Verificar antes que el del servidor sea ese: SHOW CREATE EVENT corte_pagos_48hs\G
--
-- Cambios respecto del original:
--   - Las horas se leen de Configuracion (versión vigente hoy); fallback 48.
--   - Filtra rp.id_estado_pago <> 3 (igual que PagoService::ejecutarCortePagos()), no IN (1,2).
--   - Ya NO modifica Habitaciones. El original pasaba a Disponible la habitación de la reserva
--     cancelada aunque estuviera ocupada hoy por otro huésped. Una reserva futura cancelada
--     no cambia el estado físico de la habitación.
--   - Una transacción con handler: si falla, se revierte y queda en el log de errores.
--   - Observación en el mismo formato que el PHP.
-- Se mantiene: corre 1 vez por día a las 08:00 (fecha_entrada es DATE; correr más seguido
-- adelantaría la cancelación a las 00:00).
-- Requiere: event_scheduler = ON y privilegio EVENT.

DROP EVENT IF EXISTS `corte_pagos_48hs`;
DROP EVENT IF EXISTS `corte_pagos`;

DELIMITER $$

CREATE EVENT `corte_pagos`
ON SCHEDULE EVERY 1 DAY
STARTS (CURDATE() + INTERVAL 1 DAY + INTERVAL 8 HOUR)
ON COMPLETION PRESERVE
ENABLE
COMMENT 'Cancela reservas sin pago total N horas antes del check-in (N = Configuracion.horas_corte_pago)'
DO BEGIN
    DECLARE v_horas INT DEFAULT NULL;
    DECLARE v_fecha DATE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        DROP TEMPORARY TABLE IF EXISTS tmp_corte_pagos;
        RESIGNAL;
    END;

    SELECT CAST(valor AS UNSIGNED) INTO v_horas
    FROM Configuracion
    WHERE clave = 'horas_corte_pago' AND vigente_desde <= NOW()
    ORDER BY vigente_desde DESC
    LIMIT 1;

    SET v_horas = COALESCE(v_horas, 48);
    SET v_fecha = DATE(NOW() + INTERVAL v_horas HOUR);

    START TRANSACTION;

    -- Fijar candidatos antes de modificar estados (mismo SELECT que el PHP, con bloqueo).
    DROP TEMPORARY TABLE IF EXISTS tmp_corte_pagos;
    CREATE TEMPORARY TABLE tmp_corte_pagos ENGINE=MEMORY AS
    SELECT r.id AS id_reserva, rp.id AS id_resumen, rp.id_estado_pago
    FROM Reservas r
    JOIN Resumen_Pago rp ON rp.id_reserva = r.id
    WHERE r.fecha_entrada = v_fecha
      AND r.id_estado_reserva IN (1, 2)
      AND rp.id_estado_pago <> 3
    FOR UPDATE;

    -- Pago parcial (2) => A reembolsar (5)
    UPDATE Resumen_Pago rp
    JOIN tmp_corte_pagos t ON t.id_resumen = rp.id
    SET rp.id_estado_pago = 5
    WHERE t.id_estado_pago = 2;

    -- Reserva => Cancelada (3)
    UPDATE Reservas r
    JOIN tmp_corte_pagos t ON t.id_reserva = r.id
    SET r.id_estado_reserva = 3,
        r.observaciones = CONCAT(COALESCE(r.observaciones, ''),
            ' | Cancelada automática ', v_horas, 'hs antes del check-in por falta de pago total.');

    COMMIT;
    DROP TEMPORARY TABLE IF EXISTS tmp_corte_pagos;
END$$

DELIMITER ;

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('005_event_corte_pagos');
