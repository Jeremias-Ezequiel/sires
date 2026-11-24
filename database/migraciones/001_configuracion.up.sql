-- 001_configuracion.up.sql
-- Políticas de negocio versionadas por fecha (reemplazo futuro de App/Config/Politicas.php).
-- Regla de uso: NUNCA hacer UPDATE ni DELETE. Un cambio de política es un INSERT
-- con un vigente_desde nuevo; así queda historial y auditoría (modificado_por).
-- Usuarios.id es int(11) con signo: la FK usa el mismo tipo.
CREATE TABLE IF NOT EXISTS `Configuracion` (
  `clave`          VARCHAR(64)  NOT NULL,
  `valor`          VARCHAR(255) NOT NULL,
  `tipo`           ENUM('int','decimal','time','json') NOT NULL,
  `descripcion`    VARCHAR(255) DEFAULT NULL,
  `vigente_desde`  DATETIME     NOT NULL,
  `modificado_por` INT(11)      DEFAULT NULL,
  `fecha_alta`     DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`clave`, `vigente_desde`),
  KEY `idx_configuracion_usuario` (`modificado_por`),
  CONSTRAINT `fk_configuracion_usuario` FOREIGN KEY (`modificado_por`) REFERENCES `Usuarios` (`id`),
  CONSTRAINT `chk_configuracion_json` CHECK (`tipo` <> 'json' OR json_valid(`valor`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Valores iniciales = los de App/Config/Politicas.php a la fecha de esta migración.
INSERT IGNORE INTO `Configuracion` (`clave`, `valor`, `tipo`, `descripcion`, `vigente_desde`) VALUES
('horas_corte_pago',           '48',    'int',     'Horas antes del check-in para cancelar sin pago total', '2000-01-01 00:00:00'),
('hora_checkout',              '11:00', 'time',    'Hora límite de checkout / mínima para No-Show',         '2000-01-01 00:00:00'),
('factor_late_checkout',       '0.5',   'decimal', 'Fracción de la noche cobrada por late checkout',        '2000-01-01 00:00:00'),
('descuento_ocupacion',        '{"3":{"1":25,"2":10,"3":0},"2":{"1":15,"2":0}}', 'json', 'Descuento % por capacidad y cantidad de huéspedes', '2000-01-01 00:00:00'),
('minutos_token_recuperacion', '60',    'int',     'Validez del token de recuperación de contraseña',       '2000-01-01 00:00:00');

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('001_configuracion');
