-- 013_auditoria.up.sql
-- Registro de quién hizo qué: reservas, pagos y reembolsos, habitaciones, empleados y
-- configuración. Lo escribe App\Services\AuditoriaService dentro de la misma transacción
-- que la operación (si la operación falla, no queda registro).
-- antes / despues: JSON solo con los campos que cambiaron.
-- id_usuario NULL = proceso automático (cron por CLI).
-- Aditiva: tabla nueva.

CREATE TABLE IF NOT EXISTS `Auditoria` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `id_usuario` int(11) DEFAULT NULL,
  `accion` varchar(50) NOT NULL,
  `entidad` varchar(30) NOT NULL,
  `id_entidad` int(11) DEFAULT NULL,
  `descripcion` varchar(255) NOT NULL,
  `antes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`antes` IS NULL OR json_valid(`antes`)),
  `despues` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`despues` IS NULL OR json_valid(`despues`)),
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_auditoria_entidad` (`entidad`, `id_entidad`, `fecha`),
  KEY `idx_auditoria_fecha` (`fecha`),
  KEY `idx_auditoria_usuario` (`id_usuario`, `fecha`),
  CONSTRAINT `fk_auditoria_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `Usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('013_auditoria');
