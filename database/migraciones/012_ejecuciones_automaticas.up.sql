-- 012_ejecuciones_automaticas.up.sql
-- Monitoreo de los procesos automáticos: una fila por corrida de cada EVENT
-- (corte_pagos, cargo_late_checkout, liberacion_no_show) o de su cron PHP de respaldo.
-- La app (MonitoreoService) avisa en el inicio si un proceso no corre o falló.
-- Aditiva: tabla nueva. Los EVENTs que la escriben se crean con los scripts 01 y 02
-- (correrlos después de esta migración en una base existente).

CREATE TABLE IF NOT EXISTS `Ejecuciones_Automaticas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `proceso` varchar(50) NOT NULL,
  `origen` enum('event','cron') NOT NULL DEFAULT 'event',
  `inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fin` datetime DEFAULT NULL,
  `filas_afectadas` int(11) DEFAULT NULL,
  `ok` tinyint(1) DEFAULT NULL COMMENT 'NULL = en curso o cortada; 1 = ok; 0 = error',
  `error` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ejecuciones_proceso_inicio` (`proceso`, `inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('012_ejecuciones_automaticas');
