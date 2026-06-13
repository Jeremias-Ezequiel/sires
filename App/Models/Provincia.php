<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Provincia extends Model
{
    // =====================================================================
    // ATRIBUTOS PRIVADOS (Actualizados con la nueva base de datos)
    // =====================================================================
    private int $id;
    private int $id_nacionalidad; // 🔗 Relación jerárquica con Nacionalidades
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
            // ✨ SOLUCIÓN ULTRA ESTRICTA: Filtramos provincias duplicadas por texto vinculando solo el primer ID que encuentre
            $sql = "SELECT DISTINCT p1.id, p1.id_nacionalidad, TRIM(p1.descripcion) AS descripcion, p1.is_active, '' AS fecha_alta, NULL AS fecha_baja
                    FROM Provincias p1
                    WHERE p1.is_active = 1
                    AND p1.id = (
                        SELECT MIN(p2.id) 
                        FROM Provincias p2 
                        WHERE TRIM(p2.descripcion) = TRIM(p1.descripcion)
                    )
                    ORDER BY descripcion ASC";
                    
            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Provincia::class);
            $provinces = $stmt->fetchAll();

            return $provinces ?: null;
        } catch (PDOException $e) {
            error_log("Error in getAll provinces : " . $e->getMessage());
            throw new Exception("Database error during provinces lookup.");
        }
    }

    /**
     * ⚡ NUEVO MÉTODO: Filtra provincias según la nacionalidad (país) seleccionada
     */
    public function getByNacionalidad(int $id_nacionalidad): ?array
    {
        try {
            $sql = "SELECT * FROM Provincias WHERE id_nacionalidad = :id_nacionalidad ORDER BY descripcion ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id_nacionalidad' => $id_nacionalidad]);

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Provincia::class);
            $provinces = $stmt->fetchAll();

            return $provinces ?: null;
        } catch (PDOException $e) {
            error_log("Error in getByNacionalidad provinces: " . $e->getMessage());
            throw new Exception("Database error during provinces filtering.");
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

    public function getIdNacionalidad(): int
    {
        return $this->id_nacionalidad;
    }
    public function setIdNacionalidad(int $id_nacionalidad): void
    {
        if ($id_nacionalidad <= 0) {
            throw new Exception("La nacionalidad vinculada no es válida.");
        }
        $this->id_nacionalidad = $id_nacionalidad;
    }

    public function getDescripcion(): string
    {
        return $this->descripcion;
    }
    public function setDescripcion(string $descripcion): void
    {
        $cleanDesc = htmlspecialchars(trim($descripcion), ENT_QUOTES, 'UTF-8');
        if (empty($cleanDesc)) {
            throw new Exception("La descripción de la provincia no puede estar vacía.");
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
