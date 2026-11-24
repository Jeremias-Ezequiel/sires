-- 009_estado_habitacion_retenida.down.sql
-- Antes: desplegar el código anterior y recrear el EVENT liberacion_no_show previo
-- (versión de scripts/02 que pasa de Ocupada a Disponible).
UPDATE `Habitaciones` SET `id_estado_habitacion` = 2 WHERE `id_estado_habitacion` = 7;
DELETE FROM `Estados_Habitacion` WHERE `id` = 7;
DELETE FROM `migraciones` WHERE `nombre` = '009_estado_habitacion_retenida';
