-- ============================================================
-- ELIMINAR TABLA DE HUÉSPEDES - SIRES
-- Fecha: 2026-09-29
-- ============================================================
-- La tabla Huespedes no está cumpliendo ninguna función actual:
-- - Está vacía (sin datos)
-- - No se usa en modelos ni controladores
-- - No se muestra en ninguna vista
-- El sistema usa cantidad_huespedes, adultos y ninos en Reservas
-- ============================================================

DROP TABLE IF EXISTS Huespedes;
