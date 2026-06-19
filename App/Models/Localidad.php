<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Localidad extends Model
{
    // =====================================================================
    // ATRIBUTOS PRIVADOS (Actualizados con la nueva base de datos)
    // =====================================================================
    private int $id;
    private int $id_provincia; // 🔗 Relación jerárquica con Provincias
    private string $descripcion;
    private int $is_active;
    private string $fecha_alta;
    private ?string $fecha_baja = null;

    // =====================================================================
    // MÉTODOS DE NEGOCIO
    // =====================================================================

    public function getAll(): ?array
    {
        try {
            // ✨ CORREGIDO: Agrupamos estrictamente por el nombre de la localidad borrando duplicados reales de la BD
            $sql = "SELECT MAX(id) AS id, MAX(id_provincia) AS id_provincia, TRIM(descripcion) AS descripcion, MAX(is_active) AS is_active 
                    FROM Localidades 
                    WHERE is_active = 1 
                    GROUP BY TRIM(descripcion) 
                    ORDER BY descripcion ASC";
                    
            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Localidad::class);
            $cities = $stmt->fetchAll();

            return $cities ?: null;
        } catch (PDOException $e) {
            error_log("Error in getAll Cities: " . $e->getMessage());
            throw new Exception("Database error during cities lookup.");
        }
    }

    /**
     * ⚡ NUEVOS MÉTODOS: Filtra localidades según la provincia seleccionada
     */
    public function getByProvincia(int $id_provincia): ?array
    {
        try {
            $sql = "SELECT * FROM Localidades WHERE id_provincia = :id_provincia ORDER BY descripcion ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id_provincia' => $id_provincia]);

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Localidad::class);
            $cities = $stmt->fetchAll();

            return $cities ?: null;
        } catch (PDOException $e) {
            error_log("Error in getByProvincia Cities: " . $e->getMessage());
            throw new Exception("Database error during cities filtering.");
        }
    }

    // =====================================================================
    // GETTERS Y SETTERS
    // =====================================================================

    public function getId(): int
    {
        return $this->id;
    }
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getIdProvincia(): int
    {
        return $this->id_provincia;
    }
    public function setIdProvincia(int $id_provincia): void
    {
        if ($id_provincia <= 0) {
            throw new Exception("La provincia vinculada no es válida.");
        }
        $this->id_provincia = $id_provincia;
    }

    public function getDescripcion(): string
    {
        return $this->descripcion;
    }
    public function setDescripcion(string $descripcion): void
    {
        $cleanDesc = htmlspecialchars(trim($descripcion), ENT_QUOTES, 'UTF-8');
        if (empty($cleanDesc)) {
            throw new Exception("La descripción de la localidad no puede estar vacía.");
        }
        $this->descripcion = $cleanDesc;
    }

    public function getIsActive(): int
    {
        return $this->is_active;
    }
    public function setIsActive(int $is_active): void
    {
        if ($is_active !== 0 && $is_active !== 1) {
            throw new Exception("El estado de actividad debe ser 0 o 1.");
        }
        $this->is_active = $is_active;
    }

    public function getFechaAlta(): string
    {
        return $this->fecha_alta;
    }
    public function setFechaAlta(string $fecha_alta): void
    {
        $this->fecha_alta = $fecha_alta;
    }

    public function getFechaBaja(): ?string
    {
        return $this->fecha_baja;
    }
    public function setFechaBaja(?string $fecha_baja): void
    {
        $this->fecha_baja = $fecha_baja;
    }
}
