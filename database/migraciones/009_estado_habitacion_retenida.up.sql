-- 009_estado_habitacion_retenida.up.sql
-- Estado de habitación 7 'Retenida': No-Show con pago. Nadie la ocupa, pero se guarda para el
-- huésped hasta la liberación (antes quedaba 'Ocupada' y se confundía con un huésped alojado).
-- - ReservaService::marcarNoShow(): Disponible => Retenida.
-- - ReservaService::checkIn() desde No-Show con pago: Retenida => Ocupada.
-- - ReservaService::liberarNoShowConPago() y EVENT liberacion_no_show: Retenida => Disponible.
-- Después de aplicarla, recrear el EVENT con scripts/02_eventos_late_fee_y_no_show.sql.

INSERT IGNORE INTO `Estados_Habitacion` (`id`, `descripcion`, `is_active`) VALUES (7, 'Retenida', 1);

-- Habitaciones que hoy están 'Ocupada' solo por un No-Show con pago (sin huésped En casa).
UPDATE `Habitaciones` h
SET h.`id_estado_habitacion` = 7
WHERE h.`id_estado_habitacion` = 2
  AND EXISTS (SELECT 1 FROM `Reservas` r WHERE r.`id_habitacion` = h.`id` AND r.`id_estado_reserva` = 7)
  AND NOT EXISTS (SELECT 1 FROM `Reservas` o WHERE o.`id_habitacion` = h.`id` AND o.`id_estado_reserva` = 5);

INSERT IGNORE INTO `migraciones` (`nombre`) VALUES ('009_estado_habitacion_retenida');
