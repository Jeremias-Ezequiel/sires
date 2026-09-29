-- ============================================================
-- SISTEMA DE IMPUESTOS - SIRES
-- Fecha: 2026-09-29
-- ============================================================

-- Tabla de impuestos
CREATE TABLE IF NOT EXISTS Impuestos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    porcentaje DECIMAL(5,2) NOT NULL,
    tipo ENUM('IVA', 'TURISMO', 'CITY_TAX', 'OTRO') NOT NULL DEFAULT 'IVA',
    is_active TINYINT(1) DEFAULT 1,
    fecha_alta DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_baja DATETIME NULL
);

-- Tabla de relación entre resumen de pago e impuestos
CREATE TABLE IF NOT EXISTS Resumen_Impuestos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_resumen_pago INT NOT NULL,
    id_impuesto INT NOT NULL,
    monto_base DECIMAL(10,2) NOT NULL,
    monto_impuesto DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (id_resumen_pago) REFERENCES Resumen_Pago(id) ON DELETE CASCADE,
    FOREIGN KEY (id_impuesto) REFERENCES Impuestos(id)
);

-- Insertar impuestos básicos (si no existen)
SET @exist := (SELECT COUNT(*) FROM Impuestos WHERE nombre = 'IVA 21%');
SET @sql := IF(@exist = 0, 'INSERT INTO Impuestos (nombre, porcentaje, tipo) VALUES (''IVA 21%'', 21.00, ''IVA'')', 'SELECT "IVA 21% already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM Impuestos WHERE nombre = 'Impuesto al Turismo');
SET @sql := IF(@exist = 0, 'INSERT INTO Impuestos (nombre, porcentaje, tipo) VALUES (''Impuesto al Turismo'', 3.50, ''TURISMO'')', 'SELECT "Impuesto al Turismo already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM Impuestos WHERE nombre = 'City Tax');
SET @sql := IF(@exist = 0, 'INSERT INTO Impuestos (nombre, porcentaje, tipo) VALUES (''City Tax'', 2.00, ''CITY_TAX'')', 'SELECT "City Tax already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
