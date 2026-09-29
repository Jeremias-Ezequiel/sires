-- ============================================================
-- TABLA DE CONSUMOS (MINIBAR Y SERVICIOS) - SIRES
-- Fecha: 2026-09-29
-- ============================================================

CREATE TABLE IF NOT EXISTS Consumos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_reserva INT NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    id_estado INT NOT NULL DEFAULT 1, -- 1=Pendiente, 2=Facturado, 3=Anulado
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_reserva) REFERENCES Reservas(id) ON DELETE CASCADE
);

-- Tabla de estados de consumos (si no existe)
CREATE TABLE IF NOT EXISTS Consumos_Estados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    descripcion VARCHAR(50) NOT NULL
);

-- Insertar estados de consumos (si no existen)
SET @exist := (SELECT COUNT(*) FROM Consumos_Estados);
SET @sql := IF(@exist = 0, 'INSERT INTO Consumos_Estados (descripcion) VALUES (''Pendiente''), (''Facturado''), (''Anulado'')', 'SELECT "Consumos_Estados already populated" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
