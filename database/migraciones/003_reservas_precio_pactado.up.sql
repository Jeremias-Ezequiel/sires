-- 003_reservas_precio_pactado.up.sql
-- Problema: PagoService::aplicarLateCheckout() usa Habitaciones.precio_noche_base ACTUAL.
-- Si el precio sube, una reserva vieja paga el recargo con el precio nuevo.
-- Solución: guardar el precio efectivo por noche pactado al crear/editar la reserva.
-- Aditivo: columna NULL; el código actual la ignora. Usar COALESCE(precio_noche_pactado, precio_noche_base).

ALTER TABLE `Reservas`
  ADD COLUMN IF NOT EXISTS `precio_noche_pactado` DECIMAL(10,2) DEFAULT NULL AFTER `cantidad_huespedes`,
  ADD CONSTRAINT `chk_reserva_precio_pactado`
      CHECK (`precio_noche_pactado` IS NULL OR (`precio_noche_pactado` > 0 AND `precio_noche_pactado` <= 999999.99));

-- Backfill aproximado: total / noches.
-- Se excluyen reservas con late fee ya aplicado (monto_total lo incluye): quedan NULL
-- y el código usa precio_noche_base como fallback. Completarlas a mano si hace falta.
UPDATE `Reservas` r
JOIN `Resumen_Pago` rp ON rp.id_reserva = r.id
SET r.precio_noche_pactado = ROUND(rp.monto_total / DATEDIFF(r.fecha_salida, r.fecha_entrada), 2)
WHERE r.precio_noche_pactado IS NULL
  AND rp.monto_total > 0
  AND DATEDIFF(r.fecha_salida, r.fecha_entrada) > 0
  AND (r.observaciones IS NULL OR r.observaciones NOT LIKE '%Late Checkout Fee Aplicado%');

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('003_reservas_precio_pactado');
