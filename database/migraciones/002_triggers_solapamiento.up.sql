-- 002_triggers_solapamiento.up.sql
-- Problema: trg_check_solapamiento_update valida solapamiento en TODO UPDATE de Reservas,
-- aunque la fila editada no esté activa (Cancelada, Finalizada, No-Show).
-- Ej.: reserva #1 Cancelada y reserva #2 Confirmada en la misma habitación y fechas.
-- Cualquier UPDATE sobre #1 (observaciones, backfill de 003/004) falla con SQLSTATE 45000.
-- Corrección: solo validar cuando la fila NUEVA queda en un estado que ocupa la habitación.
-- Estados que ocupan (EstadoReserva): 1 Pendiente, 2 Confirmada, 5 En casa, 7 No-Show con pago.
-- Debe aplicarse ANTES de 003 y 004 (hacen UPDATE masivo sobre Reservas).
-- Requiere privilegio TRIGGER (y SUPER/log_bin_trust_function_creators si hay binlog).

DROP TRIGGER IF EXISTS `trg_check_solapamiento_insert`;
DROP TRIGGER IF EXISTS `trg_check_solapamiento_update`;

DELIMITER $$

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

DELIMITER ;

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('002_triggers_solapamiento');
