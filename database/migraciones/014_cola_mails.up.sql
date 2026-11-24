-- 014_cola_mails.up.sql
-- Mails al huésped (SIRES 1.6.0): confirmación al crear la reserva, recordatorio N días
-- antes de la entrada y comprobante de cada pago. Los encola App\Services\MailService
-- dentro de la transacción de la operación y los manda cron/mails.php (cada 5 minutos),
-- con reintentos con espera creciente (máximo 5 intentos).
-- clave_unica evita encolar dos veces el mismo mail (ej. 'recordatorio:15:2026-12-01').
-- Clave nueva en Configuracion: recordatorio_dias_antes (3).
-- Aditiva: tabla nueva + una fila en Configuracion.

CREATE TABLE IF NOT EXISTS `Cola_Mails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tipo` enum('confirmacion','recordatorio','comprobante_pago') NOT NULL,
  `id_reserva` int(11) DEFAULT NULL,
  `clave_unica` varchar(100) NOT NULL,
  `destinatario` varchar(150) NOT NULL,
  `asunto` varchar(200) NOT NULL,
  `cuerpo_html` mediumtext NOT NULL,
  `estado` enum('pendiente','enviado','error') NOT NULL DEFAULT 'pendiente' COMMENT 'error = se agotaron los intentos',
  `intentos` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `proximo_intento` datetime DEFAULT current_timestamp(),
  `ultimo_error` varchar(500) DEFAULT NULL,
  `fecha_alta` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_envio` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cola_mails_clave` (`clave_unica`),
  KEY `idx_cola_mails_envio` (`estado`, `proximo_intento`),
  KEY `idx_cola_mails_reserva` (`id_reserva`),
  CONSTRAINT `fk_cola_mails_reserva` FOREIGN KEY (`id_reserva`) REFERENCES `Reservas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `Configuracion` (`clave`, `valor`, `tipo`, `descripcion`, `vigente_desde`) VALUES
('recordatorio_dias_antes', '3', 'int', 'Días antes de la entrada en que se manda el recordatorio al huésped', '2026-11-06 00:00:00');

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('014_cola_mails');
