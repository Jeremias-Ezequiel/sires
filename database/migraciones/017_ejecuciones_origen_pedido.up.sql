-- 017_ejecuciones_origen_pedido.up.sql
-- Ejecución por pedido de los procesos automáticos (SIRES 1.9.0).
-- En servidores sin event_scheduler ni crontab, la app corre los procesos vencidos al
-- navegar el sistema (EjecucionPorPedidoService) y registra la corrida con origen 'pedido'.
-- Aditiva: suma un valor al enum.

ALTER TABLE `Ejecuciones_Automaticas`
  MODIFY COLUMN `origen` enum('event','cron','pedido') NOT NULL DEFAULT 'event';

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('017_ejecuciones_origen_pedido');
