-- =====================================================================
-- 01_reparar_triggers_y_corte.sql
-- Recrea los 2 triggers de solapamiento (Reservas), los 2 de limpieza
-- (Habitaciones, migración 011) y el EVENT corte_pagos a nombre del
-- usuario que ejecuta este script (sin DEFINER fijo).
-- Los triggers de limpieza requieren las columnas de la 011 y el EVENT corte_pagos la
-- tabla Ejecuciones_Automaticas de la 012 (ambas en el dump v1.3.0).
--
-- Cuándo: SIEMPRE, después de importar el dump. Es idempotente.
--   - Si se importó el dump con DEFINER=root@localhost y ese usuario no existe,
--     este script lo repara (si no, todo INSERT/UPDATE en Reservas falla).
--   - Si se importó el dump sin DEFINER, deja todo igual.
-- Requiere: privilegios TRIGGER y EVENT sobre la base (incluidos en GRANT ALL).
-- Ejecutar con el cliente mariadb o DBeaver > Tools > Execute script (usa DELIMITER).
-- =====================================================================

DROP TRIGGER IF EXISTS `trg_check_solapamiento_insert`;
DROP TRIGGER IF EXISTS `trg_check_solapamiento_update`;
DROP TRIGGER IF EXISTS `trg_habitacion_limpieza_insert`;
DROP TRIGGER IF EXISTS `trg_habitacion_limpieza_update`;
DROP EVENT IF EXISTS `corte_pagos`;
DROP EVENT IF EXISTS `corte_pagos_48hs`;

DELIMITER $$

-- Solo valida solapamiento si la fila queda en un estado que ocupa la habitación:
-- 1 Pendiente, 2 Confirmada, 5 En casa, 7 No-Show con pago.
CREATE TRIGGER `trg_check_solapamiento_insert` BEFORE INSERT ON `Reservas` FOR EACH ROW
BEGIN
    DECLARE solapado INT DEFAULT 0;
    IF NEW.id_estado_reserva IN (1, 2, 5, 7) THEN
        SELECT COUNT(*) INTO solapado FROM Reservas
        WHERE id_habitacion = NEW.id_habitacion
          AND id_estado_reserva IN (1, 2, 5, 7)
          AND NEW.fecha_entrada < fecha_salida
          AND NEW.fecha_salida > fecha_entrada;
        IF solapado > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La habitación ya tiene una reserva solapada en esas fechas.';
        END IF;
    END IF;
END$$

CREATE TRIGGER `trg_check_solapamiento_update` BEFORE UPDATE ON `Reservas` FOR EACH ROW
BEGIN
    DECLARE solapado INT DEFAULT 0;
    IF NEW.id_estado_reserva IN (1, 2, 5, 7) THEN
        SELECT COUNT(*) INTO solapado FROM Reservas
        WHERE id_habitacion = NEW.id_habitacion
          AND id <> NEW.id
          AND id_estado_reserva IN (1, 2, 5, 7)
          AND NEW.fecha_entrada < fecha_salida
          AND NEW.fecha_salida > fecha_entrada;
        IF solapado > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La habitación ya tiene una reserva solapada en esas fechas.';
        END IF;
    END IF;
END$$

-- Corte de pagos: igual a PagoService::ejecutarCortePagos(). Corre 1 vez por día a las 08:00.
-- Horas desde Configuracion.horas_corte_pago (fallback 48). No modifica Habitaciones.
-- Cancela (sin pago total):
--   - reservas Pendientes o Confirmadas con entrada = hoy + N horas;
--   - reservas Pendientes con la entrada ya vencida (nunca expiraban).
-- La seña no se reembolsa: monto_retenido = monto_total = lo cobrado, saldo 0, estado Retenido (6).
CREATE EVENT `corte_pagos`
ON SCHEDULE EVERY 1 DAY
STARTS (CURDATE() + INTERVAL IF(CURTIME() < '08:00:00', 0, 1) DAY + INTERVAL 8 HOUR)
ON COMPLETION PRESERVE
ENABLE
COMMENT 'Cancela reservas sin pago total N horas antes del check-in y pendientes vencidas; retiene la seña'
DO BEGIN
    DECLARE v_horas INT DEFAULT NULL;
    DECLARE v_fecha DATE;

    DECLARE v_id_ejecucion BIGINT DEFAULT NULL;
    DECLARE v_filas INT DEFAULT 0;
    DECLARE v_error TEXT DEFAULT NULL;

    -- Si algo falla: deshace, deja el error en Ejecuciones_Automaticas (migración 012) y lo propaga
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1 v_error = MESSAGE_TEXT;
        IF v_id_ejecucion IS NOT NULL THEN
            UPDATE Ejecuciones_Automaticas
            SET fin = NOW(), ok = 0, error = v_error
            WHERE id = v_id_ejecucion;
        END IF;
        DROP TEMPORARY TABLE IF EXISTS tmp_corte_pagos;
        RESIGNAL;
    END;

    -- Registro de la corrida (para el monitoreo de la app)
    INSERT INTO Ejecuciones_Automaticas (proceso, origen) VALUES ('corte_pagos', 'event');
    SET v_id_ejecucion = LAST_INSERT_ID();

    SELECT CAST(valor AS UNSIGNED) INTO v_horas
    FROM Configuracion
    WHERE clave = 'horas_corte_pago' AND vigente_desde <= NOW()
    ORDER BY vigente_desde DESC
    LIMIT 1;

    SET v_horas = COALESCE(v_horas, 48);
    SET v_fecha = DATE(NOW() + INTERVAL v_horas HOUR);

    START TRANSACTION;

    DROP TEMPORARY TABLE IF EXISTS tmp_corte_pagos;
    CREATE TEMPORARY TABLE tmp_corte_pagos ENGINE=MEMORY AS
    SELECT r.id AS id_reserva, rp.id AS id_resumen, rp.id_estado_pago
    FROM Reservas r
    JOIN Resumen_Pago rp ON rp.id_reserva = r.id
    WHERE rp.id_estado_pago <> 3
      AND (   (r.fecha_entrada = v_fecha AND r.id_estado_reserva IN (1, 2))
           OR (r.fecha_entrada < CURDATE() AND r.id_estado_reserva = 1))
    FOR UPDATE;

    SELECT COUNT(*) INTO v_filas FROM tmp_corte_pagos;

    -- Con cobros => se retiene la seña: Retenido (6). Igual a PagoService::aplicarRetencion()
    UPDATE Resumen_Pago rp
    JOIN tmp_corte_pagos t ON t.id_resumen = rp.id
    SET rp.monto_total     = rp.monto_cobrado,
        rp.saldo_pendiente = 0,
        rp.monto_retenido  = rp.monto_cobrado,
        rp.id_estado_pago  = 6
    WHERE rp.monto_cobrado > 0;

    -- Reserva => Cancelada (3)
    UPDATE Reservas r
    JOIN tmp_corte_pagos t ON t.id_reserva = r.id
    JOIN Resumen_Pago rp ON rp.id = t.id_resumen
    SET r.id_estado_reserva = 3,
        -- Un mensaje por línea ("- Mensaje"), igual que Reserva::SQL_SEPARADOR_OBS (SIRES 1.8.0)
        r.observaciones = CONCAT(COALESCE(r.observaciones, ''),
            IF(COALESCE(r.observaciones, '') = '', '- ', CONCAT(CHAR(10), '- ')),
            IF(r.fecha_entrada < CURDATE(),
               'Cancelada automática: reserva pendiente con la fecha de entrada vencida.',
               CONCAT('Cancelada automática ', v_horas, 'hs antes del check-in por falta de pago total.')),
            IF(rp.monto_cobrado > 0, ' Seña retenida.', ''));

    COMMIT;
    DROP TEMPORARY TABLE IF EXISTS tmp_corte_pagos;

    UPDATE Ejecuciones_Automaticas
    SET fin = NOW(), ok = 1, filas_afectadas = v_filas
    WHERE id = v_id_ejecucion;

    -- Historial de corridas: se guardan 90 días
    DELETE FROM Ejecuciones_Automaticas WHERE inicio < NOW() - INTERVAL 90 DAY;
END$$

-- Limpieza (migración 011): al pasar a Sucia (5) guarda desde cuándo;
-- al salir de Sucia/Limpiando (5/6) borra la fecha y la marca de urgente.
CREATE TRIGGER `trg_habitacion_limpieza_insert` BEFORE INSERT ON `Habitaciones` FOR EACH ROW
BEGIN
    IF NEW.id_estado_habitacion = 5 AND NEW.sucia_desde IS NULL THEN
        SET NEW.sucia_desde = NOW();
    ELSEIF NEW.id_estado_habitacion NOT IN (5, 6) THEN
        SET NEW.sucia_desde = NULL;
        SET NEW.limpieza_urgente = 0;
    END IF;
END$$

CREATE TRIGGER `trg_habitacion_limpieza_update` BEFORE UPDATE ON `Habitaciones` FOR EACH ROW
BEGIN
    IF NEW.id_estado_habitacion = 5 AND OLD.id_estado_habitacion NOT IN (5, 6) THEN
        SET NEW.sucia_desde = NOW();
    ELSEIF NEW.id_estado_habitacion NOT IN (5, 6) THEN
        SET NEW.sucia_desde = NULL;
        SET NEW.limpieza_urgente = 0;
    END IF;
END$$

DELIMITER ;
