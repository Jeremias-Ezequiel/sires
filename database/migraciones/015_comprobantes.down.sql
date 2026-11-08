-- 015_comprobantes.down.sql
-- Seguro solo con código < 1.7.0. Borra los comprobantes emitidos y los datos del hotel.
DROP TABLE IF EXISTS `Comprobantes`;
DELETE FROM `Configuracion` WHERE `clave` IN ('hotel_nombre', 'hotel_razon_social', 'hotel_cuit',
  'hotel_condicion_iva', 'hotel_domicilio', 'hotel_telefono', 'hotel_email', 'comprobante_punto_venta');
ALTER TABLE `Configuracion`
  MODIFY `tipo` enum('int','decimal','time','json') NOT NULL;
DELETE FROM `migraciones` WHERE `nombre` = '015_comprobantes';
