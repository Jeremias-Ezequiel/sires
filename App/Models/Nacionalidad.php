<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Nacionalidad extends Model
{
    // =====================================================================
    // ATRIBUTOS PRIVADOS (Actualizados con la nueva base de datos)
    // =====================================================================
    private int $id;
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
            // ✨ SOLUCIÓN ULTRA ESTRICTA: Filtramos con DISTINCT combinando un subquery limpio
            $sql = "SELECT DISTINCT n1.id, TRIM(n1.descripcion) AS descripcion, n1.is_active, '' AS fecha_alta, NULL AS fecha_baja
                    FROM Nacionalidades n1
                    WHERE n1.is_active = 1
                    AND n1.id = (
                        SELECT MIN(n2.id) 
                        FROM Nacionalidades n2 
                        WHERE TRIM(n2.descripcion) = TRIM(n1.descripcion)
                    )
                    ORDER BY descripcion ASC";
                    
            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Nacionalidad::class);
            $countries = $stmt->fetchAll();

            return $countries ?: null;
        } catch (PDOException $e) {
            error_log("Error in getAll countries: " . $e->getMessage());
            throw new Exception("Database error during countries lookup.");
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

    public function getDescripcion(): string
    {
        return $this->descripcion;
    }
    public function setDescripcion(string $descripcion): void
    {
        $cleanDesc = htmlspecialchars(trim($descripcion), ENT_QUOTES, 'UTF-8');
        if (empty($cleanDesc)) {
            throw new Exception("La descripción de la nacionalidad no puede estar vacía.");
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
