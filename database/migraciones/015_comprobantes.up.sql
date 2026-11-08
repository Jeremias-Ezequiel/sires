-- 015_comprobantes.up.sql
-- Comprobantes PDF (SIRES 1.7.0): pagos, reembolsos y resumen de la reserva, con numeración
-- correlativa propia por tipo y punto de venta (0001-00000012). No son facturas fiscales.
-- - Pagos y reembolsos: el número se asigna en la misma transacción que registra el movimiento.
-- - Resumen: cada descarga emite un comprobante nuevo con su número.
-- - datos: copia (JSON) de los datos del hotel y de los montos al emitir; una reimpresión sale igual.
-- Configuracion: tipo nuevo 'texto' y claves de los datos del hotel + punto de venta.
-- Aditiva: tabla nueva, un valor más en el ENUM de Configuracion.tipo y filas nuevas.

ALTER TABLE `Configuracion`
  MODIFY `tipo` enum('int','decimal','time','json','texto') NOT NULL;

INSERT IGNORE INTO `Configuracion` (`clave`, `valor`, `tipo`, `descripcion`, `vigente_desde`) VALUES
('hotel_nombre',          'Hotel', 'texto', 'Nombre del hotel en comprobantes y mails',                '2026-11-06 00:00:00'),
('hotel_razon_social',    '',      'texto', 'Razón social que figura en los comprobantes',            '2026-11-06 00:00:00'),
('hotel_cuit',            '',      'texto', 'CUIT del hotel',                                          '2026-11-06 00:00:00'),
('hotel_condicion_iva',   '',      'texto', 'Condición frente al IVA',                                 '2026-11-06 00:00:00'),
('hotel_domicilio',       '',      'texto', 'Domicilio fiscal',                                        '2026-11-06 00:00:00'),
('hotel_telefono',        '',      'texto', 'Teléfono de contacto',                                    '2026-11-06 00:00:00'),
('hotel_email',           '',      'texto', 'Email de contacto',                                       '2026-11-06 00:00:00'),
('comprobante_punto_venta', '1',   'int',   'Punto de venta de la numeración de comprobantes',         '2026-11-06 00:00:00');

CREATE TABLE IF NOT EXISTS `Comprobantes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tipo` enum('pago','reembolso','resumen') NOT NULL,
  `punto_venta` smallint(5) unsigned NOT NULL DEFAULT 1,
  `numero` int(10) unsigned NOT NULL,
  `id_reserva` int(11) NOT NULL,
  `id_transaccion` int(11) DEFAULT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fecha_emision` datetime NOT NULL DEFAULT current_timestamp(),
  `emitido_por` int(11) DEFAULT NULL COMMENT 'NULL = proceso automático',
  `datos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`datos`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comprobantes_numero` (`tipo`, `punto_venta`, `numero`),
  UNIQUE KEY `uq_comprobantes_transaccion` (`id_transaccion`),
  KEY `idx_comprobantes_reserva` (`id_reserva`, `fecha_emision`),
  CONSTRAINT `fk_comprobantes_reserva` FOREIGN KEY (`id_reserva`) REFERENCES `Reservas` (`id`),
  CONSTRAINT `fk_comprobantes_transaccion` FOREIGN KEY (`id_transaccion`) REFERENCES `Transacciones_Pago` (`id`),
  CONSTRAINT `fk_comprobantes_usuario` FOREIGN KEY (`emitido_por`) REFERENCES `Usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('015_comprobantes');
