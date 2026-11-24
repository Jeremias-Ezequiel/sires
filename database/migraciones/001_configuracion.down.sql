-- 001_configuracion.down.sql
-- Revertir 005 antes (el EVENT corte_pagos lee esta tabla, aunque tiene fallback a 48).
DROP TABLE IF EXISTS `Configuracion`;
DELETE FROM `migraciones` WHERE `nombre` = '001_configuracion';
