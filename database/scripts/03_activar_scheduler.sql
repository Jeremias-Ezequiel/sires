-- =====================================================================
-- 03_activar_scheduler.sql
-- Activa el planificador de EVENTs. Sin esto, NINGÚN EVENT corre
-- (corte_pagos, cargo_late_checkout, liberacion_no_show).
--
-- Requiere privilegio SUPER (o el equivalente del hosting). Si da
-- "Access denied; you need (at least one of) the SUPER privilege(s)",
-- no se puede activar desde SQL: hay que pedírselo a quien administra el servidor.
--
-- OJO: SET GLOBAL se pierde si se reinicia MariaDB. Para que quede fijo hace falta
-- event_scheduler = ON en el archivo .cnf del servidor (no se puede desde SQL).
-- Correr 04_verificar.sql después de cada reinicio del servidor.
-- =====================================================================

SET GLOBAL event_scheduler = ON;

SHOW VARIABLES LIKE 'event_scheduler';
