<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

/**
 * Tabla Configuracion: políticas de negocio versionadas por vigente_desde.
 * Solo se lee e inserta: un cambio es una fila nueva, nunca UPDATE ni DELETE.
 * La lectura del valor vigente está en App\Services\ConfiguracionService::get().
 */
class Configuracion extends Model
{
    /** Todas las filas, de la más nueva a la más vieja por clave, con quién las cargó. */
    public function getHistorial(): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT c.clave, c.valor, c.tipo, c.descripcion, c.vigente_desde, c.fecha_alta,
                        u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
                 FROM Configuracion c
                 LEFT JOIN Usuarios u ON u.id = c.modificado_por
                 ORDER BY c.clave ASC, c.vigente_desde DESC"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Configuracion::getHistorial: " . $e->getMessage());
            throw new Exception("No se pudo leer la configuración.");
        }
    }

    public function insertar(string $clave, string $valor, string $tipo, string $descripcion, string $vigenteDesde, int $usuarioId): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO Configuracion (clave, valor, tipo, descripcion, vigente_desde, modificado_por)
                 VALUES (:clave, :valor, :tipo, :descripcion, :vigente_desde, :usuario)"
            );
            $stmt->execute([
                ':clave'         => $clave,
                ':valor'         => $valor,
                ':tipo'          => $tipo,
                ':descripcion'   => $descripcion,
                ':vigente_desde' => $vigenteDesde,
                ':usuario'       => $usuarioId,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new Exception("Ya hay un cambio de esa política programado para la misma fecha y hora.");
            }
            error_log("Error en Configuracion::insertar: " . $e->getMessage());
            throw new Exception("No se pudo guardar el cambio de configuración.");
        }
    }
}
