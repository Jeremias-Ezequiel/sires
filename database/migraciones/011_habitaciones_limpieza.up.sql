-- 011_habitaciones_limpieza.up.sql
-- Vista de housekeeping: saber desde cuándo está sucia cada habitación y permitir que
-- recepción marque una limpieza como urgente.
-- Aditivo: dos columnas con DEFAULT y dos triggers nuevos sobre Habitaciones (no había ninguno).
-- El código anterior ignora las columnas, así que funciona antes y después de aplicarla.
--
-- Por qué trigger: una habitación pasa a Sucia (5) desde varios caminos (checkout, finalizar,
-- checkout anticipado en ReservaService y eventualmente EVENTs). El trigger cubre todos sin
-- depender de que cada camino se acuerde de setear la fecha.
--   * Pasa a Sucia (5)              -> sucia_desde = NOW()
--   * Sale de Sucia/Limpiando (5/6) -> sucia_desde = NULL y limpieza_urgente = 0
--   * Sucia -> Limpiando conserva sucia_desde y la marca de urgente.
-- Requiere privilegio TRIGGER (incluido en GRANT ALL). Usa DELIMITER: correr con
-- DBeaver > Tools > Execute script.

ALTER TABLE `Habitaciones`
  ADD COLUMN IF NOT EXISTS `sucia_desde` DATETIME NULL DEFAULT NULL AFTER `id_motivo_bloqueo`,
  ADD COLUMN IF NOT EXISTS `limpieza_urgente` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sucia_desde`;

-- Backfill: las que ya están sucias o en limpieza no tienen fecha real; se toma el momento
-- de la migración para que entren al orden de la lista.
UPDATE `Habitaciones`
SET sucia_desde = NOW()
WHERE id_estado_habitacion IN (5, 6) AND sucia_desde IS NULL;

DROP TRIGGER IF EXISTS `trg_habitacion_limpieza_insert`;
DROP TRIGGER IF EXISTS `trg_habitacion_limpieza_update`;

DELIMITER $$

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

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('011_habitaciones_limpieza');
