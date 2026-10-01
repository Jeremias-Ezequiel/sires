-- =====================================================================
-- SIRES - Hotel Reservation Management System
-- Script: Eventos programados (MySQL Events)
-- Versión: 0.7.0
-- Fecha: 2026-10-01
-- =====================================================================
-- USO:   source siresDB-eventos.sql
-- REQUISITO: Haber ejecutado primero siresDB-inserts.sql
-- VER:   SHOW EVENTS;
-- VER:   SHOW VARIABLES LIKE 'event_scheduler';
-- ELIMINAR: DROP EVENT IF EXISTS corte_pagos_48hs;
-- DESACTIVAR: ALTER EVENT corte_pagos_48hs DISABLE;
-- =====================================================================

-- =====================================================================
-- EVENTO 1: Corte de Pagos 48 horas antes del Check-in
-- =====================================================================
-- Corre todos los días a las 08:00 AM.
-- Busca reservas con fecha_entrada = hoy + 2 días que no estén pagadas al 100%.
--   - Si está Pendiente (0%): la cancela sin reembolso.
--   - Si está Pago Parcial: reembolsa y la cancela.
--   - Si está Pagado Total: no la toca.
-- =====================================================================
DELIMITER //
DROP EVENT IF EXISTS corte_pagos_48hs //
CREATE EVENT corte_pagos_48hs
ON SCHEDULE EVERY 1 DAY
STARTS '2026-10-02 08:00:00'
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
                WHEN 2 THEN 'Pago parcial reembolsado'
                ELSE 'Desconocido'
            END,
            '. Fecha: ', NOW()
        ),
        rp.id_estado_pago = CASE
            WHEN rp.id_estado_pago = 2 THEN 4   -- Pago Parcial -> Reembolsado
            ELSE rp.id_estado_pago               -- Pendiente se queda igual
        END,
        rp.monto_cobrado = CASE
            WHEN rp.id_estado_pago = 2 THEN 0
            ELSE rp.monto_cobrado
        END,
        rp.saldo_pendiente = CASE
            WHEN rp.id_estado_pago = 2 THEN 0
            ELSE rp.saldo_pendiente
        END
    WHERE r.fecha_entrada = DATE_ADD(CURDATE(), INTERVAL 2 DAY)
      AND r.id_estado_reserva IN (1, 2)          -- Pendiente o Confirmada
      AND rp.id_estado_pago IN (1, 2);           -- Pendiente o Pago Parcial

    -- Liberar habitaciones de las reservas canceladas
    UPDATE Habitaciones h
    JOIN Reservas r ON r.id_habitacion = h.id
    SET h.id_estado_habitacion = 1
    WHERE r.id_estado_reserva = 3
      AND h.id_estado_habitacion = 2
      AND r.fecha_entrada <= DATE_ADD(CURDATE(), INTERVAL 2 DAY);
END //
DELIMITER ;

-- =====================================================================
-- EVENTO 2: Liberación de No-Show con Pago
-- =====================================================================
-- Corre cada 30 minutos.
-- Libera habitaciones retenidas por No-Show con pago.
-- La retención dura hasta el día después de fecha_salida a las 11:00 AM.
-- =====================================================================
DELIMITER //
DROP EVENT IF EXISTS liberar_no_show //
CREATE EVENT liberar_no_show
ON SCHEDULE EVERY 30 MINUTE
STARTS '2026-10-02 00:00:00'
COMMENT 'Libera habitaciones retenidas por No-Show con pago al día siguiente del checkout'
DO
BEGIN
    -- Cambiar estado de la reserva a No-Show simple
    UPDATE Reservas r
    SET r.id_estado_reserva = 6,  -- NO_SHOW
        r.observaciones = CONCAT(
            COALESCE(r.observaciones, ''),
            ' | Liberación automática tras retención por No-Show con pago. Fecha: ', NOW()
        )
    WHERE r.id_estado_reserva = 7  -- NO_SHOW_CON_PAGO
      AND (r.fecha_salida < CURDATE()
           OR (r.fecha_salida = CURDATE() AND CURTIME() >= '11:00:00'));

    -- Liberar las habitaciones que estaban retenidas
    UPDATE Habitaciones h
    JOIN Reservas r ON r.id_habitacion = h.id
    SET h.id_estado_habitacion = 1  -- DISPONIBLE
    WHERE r.id_estado_reserva = 6
      AND h.id_estado_habitacion = 2;  -- OCUPADA
END //
DELIMITER ;

-- =====================================================================
-- EVENTO 3: Late Checkout Fee (Cargo por salida tardía)
-- =====================================================================
-- Corre cada 15 minutos.
-- Detecta reservas EN_CASA cuya fecha de salida es hoy, ya pasaron
-- las 11:00 AM y no se ha hecho checkout.
-- Aplica cargo automático de media noche extra.
-- =====================================================================
DELIMITER //
DROP EVENT IF EXISTS late_checkout_fee //
CREATE EVENT late_checkout_fee
ON SCHEDULE EVERY 15 MINUTE
STARTS '2026-10-02 00:00:00'
COMMENT 'Aplica cargo de media noche extra por checkout después de las 11:00 AM'
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
            CAST(ROUND(h.precio_noche_base / 2, 2) AS CHAR),
            ' por salida después de las 11:00 AM. Fecha: ', NOW()
        )
    WHERE r.id_estado_reserva = 5           -- EN_CASA
      AND r.fecha_salida = CURDATE()
      AND CURTIME() >= '11:00:00'
      AND (r.observaciones IS NULL OR r.observaciones NOT LIKE '%Late Checkout Fee%');
END //
DELIMITER ;

-- =====================================================================
-- VERIFICACIÓN
-- =====================================================================
SHOW EVENTS\G