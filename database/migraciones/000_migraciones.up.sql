-- 000_migraciones.up.sql
-- Tabla de control: registra qué migraciones se aplicaron y cuándo.
CREATE TABLE IF NOT EXISTS `migraciones` (
  `nombre`      VARCHAR(100) NOT NULL,
  `aplicada_en` DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('000_migraciones');
