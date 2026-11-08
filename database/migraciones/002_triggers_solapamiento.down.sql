-- 002_triggers_solapamiento.down.sql
-- Restaura los triggers originales, copiados textualmente del dump 2026-11-04.

DROP TRIGGER IF EXISTS `trg_check_solapamiento_insert`;
DROP TRIGGER IF EXISTS `trg_check_solapamiento_update`;

DELIMITER $$

CREATE TRIGGER `trg_check_solapamiento_insert` BEFORE INSERT ON `Reservas` FOR EACH ROW
BEGIN
    DECLARE solapado INT;
    SELECT COUNT(*) INTO solapado FROM Reservas
    WHERE id_habitacion = NEW.id_habitacion
      AND id_estado_reserva IN (1, 2, 5, 7)
      AND NEW.fecha_entrada < fecha_salida
      AND NEW.fecha_salida > fecha_entrada;
    IF solapado > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La habitación ya tiene una reserva solapada en esas fechas.';
    END IF;
END$$

CREATE TRIGGER `trg_check_solapamiento_update` BEFORE UPDATE ON `Reservas` FOR EACH ROW
BEGIN
    DECLARE solapado INT;
    SELECT COUNT(*) INTO solapado FROM Reservas
    WHERE id_habitacion = NEW.id_habitacion
      AND id <> NEW.id
      AND id_estado_reserva IN (1, 2, 5, 7)
      AND NEW.fecha_entrada < fecha_salida
      AND NEW.fecha_salida > fecha_entrada;
    IF solapado > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La habitación ya tiene una reserva solapada en esas fechas.';
    END IF;
END$$

DELIMITER ;

DELETE FROM `migraciones` WHERE `nombre` = '002_triggers_solapamiento';
