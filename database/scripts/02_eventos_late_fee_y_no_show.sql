-- =====================================================================
-- 02_eventos_late_fee_y_no_show.sql
-- Crea los EVENTs de late checkout y de liberación de No-Show, corregidos.
--
-- Por qué: sin acceso al crontab del servidor, cron/late-checkout-fee.php y
-- cron/no-show-release.php no corren. Estos EVENTs hacen lo mismo dentro de MariaDB.
-- Reemplazan a los EVENTs originales late_checkout_fee y liberar_no_show (eliminados
-- en la migración 006 por bugs) con la lógica de los Services PHP.
--
-- Son compatibles con los crons PHP: si en algún momento también corren, no se cobra
-- ni se libera dos veces (mismo marcador de texto y mismos filtros de estado).
-- Cuándo: después de 01. Es idempotente.
-- Cada corrida queda en Ejecuciones_Automaticas (migración 012, incluida en el dump v1.3.0).
-- Requiere: privilegio EVENT. Ejecutar con mariadb o DBeaver > Tools > Execute script.
-- =====================================================================

DROP EVENT IF EXISTS `late_checkout_fee`;
DROP EVENT IF EXISTS `liberar_no_show`;
DROP EVENT IF EXISTS `cargo_late_checkout`;
DROP EVENT IF EXISTS `liberacion_no_show`;

DELIMITER $$

-- ---------------------------------------------------------------------
-- Late checkout: igual a PagoService::aplicarLateCheckout().
-- Reservas En casa (5) con fecha_salida = hoy, pasada la hora de checkout.
-- Cargo = precio por noche * factor_late_checkout (vigente a la fecha_alta de la reserva).
-- Precio: precio_noche_pactado si existe; si no, precio_noche_base de la habitación.
-- Idempotencia: late_fee_aplicado = 0 y el texto 'Late Checkout Fee Aplicado' (el que usa el PHP).
-- ---------------------------------------------------------------------
CREATE EVENT `cargo_late_checkout`
ON SCHEDULE EVERY 15 MINUTE
STARTS CURRENT_TIMESTAMP
ON COMPLETION PRESERVE
ENABLE
COMMENT 'Cobra late checkout (Configuracion.factor_late_checkout) pasada la hora de checkout'
DO BEGIN
    DECLARE v_hora TIME DEFAULT NULL;

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
        DROP TEMPORARY TABLE IF EXISTS tmp_late_fee;
        RESIGNAL;
    END;

    -- Registro de la corrida (para el monitoreo de la app)
    INSERT INTO Ejecuciones_Automaticas (proceso, origen) VALUES ('cargo_late_checkout', 'event');
    SET v_id_ejecucion = LAST_INSERT_ID();

    SELECT CAST(valor AS TIME) INTO v_hora
    FROM Configuracion
    WHERE clave = 'hora_checkout' AND vigente_desde <= NOW()
    ORDER BY vigente_desde DESC
    LIMIT 1;
    SET v_hora = COALESCE(v_hora, '11:00:00');

    IF CURTIME() >= v_hora THEN
        START TRANSACTION;

        DROP TEMPORARY TABLE IF EXISTS tmp_late_fee;
        CREATE TEMPORARY TABLE tmp_late_fee ENGINE=MEMORY AS
        SELECT r.id AS id_reserva, rp.id AS id_resumen,
               ROUND(COALESCE(r.precio_noche_pactado, h.precio_noche_base) * COALESCE((
                   SELECT CAST(c.valor AS DECIMAL(8,4))
                   FROM Configuracion c
                   WHERE c.clave = 'factor_late_checkout' AND c.vigente_desde <= r.fecha_alta
                   ORDER BY c.vigente_desde DESC
                   LIMIT 1
               ), 0.5), 2) AS fee
        FROM Reservas r
        JOIN Resumen_Pago rp ON rp.id_reserva = r.id
        JOIN Habitaciones h ON h.id = r.id_habitacion
        WHERE r.id_estado_reserva = 5
          AND r.fecha_salida = CURDATE()
          AND r.late_fee_aplicado = 0
          AND (r.observaciones IS NULL OR r.observaciones NOT LIKE '%Late Checkout Fee Aplicado%')
        FOR UPDATE;

        SELECT COUNT(*) INTO v_filas FROM tmp_late_fee;

        UPDATE Resumen_Pago rp
        JOIN tmp_late_fee t ON t.id_resumen = rp.id
        SET rp.monto_total     = rp.monto_total + t.fee,
            rp.saldo_pendiente = rp.saldo_pendiente + t.fee
        WHERE t.fee > 0;

        UPDATE Reservas r
        JOIN tmp_late_fee t ON t.id_reserva = r.id
        SET r.late_fee_aplicado = 1,
            -- Un mensaje por línea ("- Mensaje"), igual que Reserva::SQL_SEPARADOR_OBS (SIRES 1.8.0)
            r.observaciones = CONCAT(COALESCE(r.observaciones, ''),
                IF(COALESCE(r.observaciones, '') = '', '- ', CONCAT(CHAR(10), '- ')),
                'Late Checkout Fee Aplicado: $', FORMAT(t.fee, 2, 'de_DE'),
                ' por salida despues de las ', TIME_FORMAT(v_hora, '%H:%i'), ' hs.')
        WHERE t.fee > 0;

        COMMIT;
        DROP TEMPORARY TABLE IF EXISTS tmp_late_fee;
    END IF;

    UPDATE Ejecuciones_Automaticas
    SET fin = NOW(), ok = 1, filas_afectadas = v_filas
    WHERE id = v_id_ejecucion;
END$$

-- ---------------------------------------------------------------------
-- Liberación de No-Show con pago: igual a ReservaService::liberarNoShowConPago().
-- No-Show con pago (7) cuya retención terminó: el día entrada + N noches
-- (N = Configuracion.noshow_noches_retenidas VIGENTE A LA fecha_alta DE CADA RESERVA,
--  sin pasar fecha_salida) a la hora de checkout. La política aceptada al reservar no cambia.
--   - Reserva => No-Show (6).
--   - Se retienen esas N noches al precio pactado (tope: lo cobrado) en monto_retenido;
--     monto_total = cobrado y saldo 0. Si se cobró más => A Reembolsar (5), si no => Retenido (6).
--   - La habitación pasa de Retenida (7) a Disponible (1). Requiere la migración 009.
-- ---------------------------------------------------------------------
CREATE EVENT `liberacion_no_show`
ON SCHEDULE EVERY 30 MINUTE
STARTS CURRENT_TIMESTAMP
ON COMPLETION PRESERVE
ENABLE
COMMENT 'Libera No-Show con pago al terminar la retención y aplica la penalidad'
DO BEGIN
    DECLARE v_hora TIME DEFAULT NULL;

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
        DROP TEMPORARY TABLE IF EXISTS tmp_no_show;
        RESIGNAL;
    END;

    -- Registro de la corrida (para el monitoreo de la app)
    INSERT INTO Ejecuciones_Automaticas (proceso, origen) VALUES ('liberacion_no_show', 'event');
    SET v_id_ejecucion = LAST_INSERT_ID();

    SELECT CAST(valor AS TIME) INTO v_hora
    FROM Configuracion
    WHERE clave = 'hora_checkout' AND vigente_desde <= NOW()
    ORDER BY vigente_desde DESC
    LIMIT 1;
    SET v_hora = COALESCE(v_hora, '11:00:00');

    START TRANSACTION;

    DROP TEMPORARY TABLE IF EXISTS tmp_no_show;
    CREATE TEMPORARY TABLE tmp_no_show ENGINE=MEMORY AS
    SELECT r.id AS id_reserva, r.id_habitacion,
           ROUND(COALESCE(r.precio_noche_pactado, h.precio_noche_base)
                 * DATEDIFF(LEAST(r.fecha_entrada + INTERVAL p.noches DAY, r.fecha_salida), r.fecha_entrada),
                 2) AS retenido
    FROM Reservas r
    JOIN Habitaciones h ON h.id = r.id_habitacion
    -- Noches retenidas según la política vigente al crear cada reserva (default 1)
    JOIN (
        SELECT r2.id,
               COALESCE((
                   SELECT CAST(c.valor AS UNSIGNED)
                   FROM Configuracion c
                   WHERE c.clave = 'noshow_noches_retenidas' AND c.vigente_desde <= r2.fecha_alta
                   ORDER BY c.vigente_desde DESC
                   LIMIT 1
               ), 1) AS noches
        FROM Reservas r2
        WHERE r2.id_estado_reserva = 7
    ) p ON p.id = r.id
    WHERE r.id_estado_reserva = 7
      AND (LEAST(r.fecha_entrada + INTERVAL p.noches DAY, r.fecha_salida) < CURDATE()
           OR (LEAST(r.fecha_entrada + INTERVAL p.noches DAY, r.fecha_salida) = CURDATE()
               AND CURTIME() >= v_hora))
    FOR UPDATE;

    SELECT COUNT(*) INTO v_filas FROM tmp_no_show;

    UPDATE Reservas r
    JOIN tmp_no_show t ON t.id_reserva = r.id
    SET r.id_estado_reserva = 6,
        r.observaciones = CONCAT(COALESCE(r.observaciones, ''),
            IF(COALESCE(r.observaciones, '') = '', '- ', CONCAT(CHAR(10), '- ')),
            'Liberacion automatica tras retencion por No-Show con pago.')
    WHERE r.id_estado_reserva = 7;

    -- Penalidad: igual a PagoService::aplicarRetencion()
    UPDATE Resumen_Pago rp
    JOIN tmp_no_show t ON t.id_reserva = rp.id_reserva
    SET rp.id_estado_pago  = IF(rp.monto_cobrado > LEAST(t.retenido, rp.monto_cobrado), 5, 6),
        rp.monto_retenido  = LEAST(t.retenido, rp.monto_cobrado),
        rp.monto_total     = rp.monto_cobrado,
        rp.saldo_pendiente = 0
    WHERE rp.monto_cobrado > 0;

    UPDATE Habitaciones h
    JOIN (SELECT DISTINCT id_habitacion FROM tmp_no_show) t ON t.id_habitacion = h.id
    SET h.id_estado_habitacion = 1
    WHERE h.id_estado_habitacion = 7;

    COMMIT;
    DROP TEMPORARY TABLE IF EXISTS tmp_no_show;
    UPDATE Ejecuciones_Automaticas
    SET fin = NOW(), ok = 1, filas_afectadas = v_filas
    WHERE id = v_id_ejecucion;
END$$

DELIMITER ;

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('007_eventos_late_fee_y_no_show');
INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('010_politicas_por_fecha_reserva');
