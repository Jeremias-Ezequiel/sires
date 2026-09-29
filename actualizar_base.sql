-- ============================================================
-- ACTUALIZACION BASE DE DATOS - SIRES
-- Fecha: 2026-09-28
-- ============================================================

-- 1. Agregar campo descripcion a Habitaciones (si no existe)
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'Habitaciones' AND column_name = 'descripcion');
SET @sql := IF(@exist = 0, 'ALTER TABLE Habitaciones ADD COLUMN descripcion TEXT AFTER precio_noche_base', 'SELECT "Column descripcion already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Agregar rol de Mantenimiento (si no existe)
SET @exist := (SELECT COUNT(*) FROM Roles WHERE descripcion = 'Mantenimiento');
SET @sql := IF(@exist = 0, 'INSERT INTO Roles (descripcion, is_active) VALUES (''Mantenimiento'', 1)', 'SELECT "Rol Mantenimiento already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Agregar estados de habitación adicionales (si no existen)
SET @exist := (SELECT COUNT(*) FROM Estados_Habitacion WHERE descripcion = 'Limpia');
SET @sql := IF(@exist = 0, 'INSERT INTO Estados_Habitacion (descripcion) VALUES (''Limpia'')', 'SELECT "Estado Limpia already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM Estados_Habitacion WHERE descripcion = 'Sucia');
SET @sql := IF(@exist = 0, 'INSERT INTO Estados_Habitacion (descripcion) VALUES (''Sucia'')', 'SELECT "Estado Sucia already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Verificar que la tabla Motivos_Bloqueo tenga datos básicos
SET @exist := (SELECT COUNT(*) FROM Motivos_Bloqueo WHERE descripcion = 'Mantenimiento');
SET @sql := IF(@exist = 0, 'INSERT INTO Motivos_Bloqueo (descripcion) VALUES (''Mantenimiento'')', 'SELECT "Motivo Mantenimiento already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM Motivos_Bloqueo WHERE descripcion = 'Limpieza profunda');
SET @sql := IF(@exist = 0, 'INSERT INTO Motivos_Bloqueo (descripcion) VALUES (''Limpieza profunda'')', 'SELECT "Motivo Limpieza profunda already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM Motivos_Bloqueo WHERE descripcion = 'Reparación');
SET @sql := IF(@exist = 0, 'INSERT INTO Motivos_Bloqueo (descripcion) VALUES (''Reparación'')', 'SELECT "Motivo Reparación already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Agregar estados de reserva adicionales (si no existen)
SET @exist := (SELECT COUNT(*) FROM Estados_Reserva WHERE descripcion = 'No-show');
SET @sql := IF(@exist = 0, 'INSERT INTO Estados_Reserva (descripcion) VALUES (''No-show'')', 'SELECT "Estado No-show already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. Agregar métodos de pago adicionales (si no existen)
SET @exist := (SELECT COUNT(*) FROM Metodos_Pago WHERE descripcion = 'Mercado Pago');
SET @sql := IF(@exist = 0, 'INSERT INTO Metodos_Pago (descripcion, is_active) VALUES (''Mercado Pago'', 1)', 'SELECT "Método Mercado Pago already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- HOUSEKEEPING Y MANTENIMIENTO
-- ============================================================

-- 7. Tabla de tareas de housekeeping
CREATE TABLE IF NOT EXISTS Housekeeping_Tareas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_habitacion INT NOT NULL,
    id_empleado_asignado INT NULL,
    id_estado_tarea INT NOT NULL DEFAULT 1,
    tipo_tarea ENUM('limpieza_diaria', 'limpieza_profunda', 'cambio_sabanas', 'mantenimiento') NOT NULL,
    fecha_asignada DATE NOT NULL,
    fecha_completada DATETIME NULL,
    observaciones TEXT,
    FOREIGN KEY (id_habitacion) REFERENCES Habitaciones(id),
    FOREIGN KEY (id_empleado_asignado) REFERENCES Usuarios(id)
);

-- 8. Tabla de estados de tareas de housekeeping
CREATE TABLE IF NOT EXISTS Housekeeping_Estados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    descripcion VARCHAR(50) NOT NULL
);

-- 9. Tabla de insumos de limpieza
CREATE TABLE IF NOT EXISTS Housekeeping_Insumos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    descripcion VARCHAR(255) NOT NULL,
    stock_actual INT NOT NULL DEFAULT 0,
    stock_minimo INT NOT NULL DEFAULT 0,
    unidad_medida VARCHAR(20) DEFAULT 'unidades'
);

-- 10. Tabla de órdenes de mantenimiento
CREATE TABLE IF NOT EXISTS Ordenes_Mantenimiento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_habitacion INT NOT NULL,
    id_empleado_asignado INT NULL,
    id_estado INT NOT NULL DEFAULT 1,
    prioridad ENUM('baja', 'media', 'alta', 'urgente') NOT NULL DEFAULT 'media',
    descripcion TEXT NOT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_asignada DATETIME NULL,
    fecha_completada DATETIME NULL,
    observaciones TEXT,
    FOREIGN KEY (id_habitacion) REFERENCES Habitaciones(id),
    FOREIGN KEY (id_empleado_asignado) REFERENCES Usuarios(id)
);

-- 11. Tabla de estados de órdenes de mantenimiento
CREATE TABLE IF NOT EXISTS Ordenes_Mantenimiento_Estados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    descripcion VARCHAR(50) NOT NULL
);

-- Insertar datos iniciales de housekeeping (si no existen)
SET @exist := (SELECT COUNT(*) FROM Housekeeping_Estados);
SET @sql := IF(@exist = 0, 'INSERT INTO Housekeeping_Estados (descripcion) VALUES (''Pendiente''), (''En progreso''), (''Completada''), (''Inspeccionada'')', 'SELECT "Housekeeping_Estados already populated" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM Ordenes_Mantenimiento_Estados);
SET @sql := IF(@exist = 0, 'INSERT INTO Ordenes_Mantenimiento_Estados (descripcion) VALUES (''Pendiente''), (''Asignada''), (''En progreso''), (''Completada''), (''Cancelada'')', 'SELECT "Ordenes_Mantenimiento_Estados already populated" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM Housekeeping_Insumos);
SET @sql := IF(@exist = 0, 'INSERT INTO Housekeeping_Insumos (descripcion, stock_actual, stock_minimo, unidad_medida) VALUES
(''Sábanas'', 50, 10, ''unidades''),
(''Toallas'', 100, 20, ''unidades''),
(''Jabón de tocador'', 200, 50, ''unidades''),
(''Shampoo'', 150, 30, ''unidades''),
(''Acondicionador'', 150, 30, ''unidades''),
(''Papel higiénico'', 300, 60, ''rollos''),
(''Detergente'', 20, 5, ''litros''),
(''Desinfectante'', 15, 3, ''litros''),
(''Guantes de limpieza'', 100, 20, ''pares''),
(''Bolsas de basura'', 200, 40, ''unidades'')', 'SELECT "Housekeeping_Insumos already populated" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
