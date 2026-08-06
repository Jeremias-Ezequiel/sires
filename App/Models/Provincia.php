<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Provincia extends Model
{
    private int $id;
    private int $id_pais;
    private string $descripcion;
    private int $is_active;
    private string $fecha_alta;
    private ?string $fecha_baja = null;

   public function getAll(): ?array
    {
        try {
            $sql = "SELECT DISTINCT p1.id, p1.id_pais, TRIM(p1.nombre) AS descripcion
                    FROM Provincias p1
                    WHERE p1.id = (
                        SELECT MIN(p2.id) 
                        FROM Provincias p2 
                        WHERE TRIM(p2.nombre) = TRIM(p1.nombre)
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

    public function getByPais(int $id_pais): ?array
    {
        try {
            $sql = "SELECT id, id_pais, TRIM(nombre) AS descripcion
                    FROM Provincias 
                    WHERE id_pais = :id_pais 
                    ORDER BY descripcion ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id_pais' => $id_pais]);

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Provincia::class);
            $provinces = $stmt->fetchAll();

            return $provinces ?: null;
        } catch (PDOException $e) {
            error_log("Error in getByPais provinces: " . $e->getMessage());
            throw new Exception("Database error during provinces filtering.");
        }
    }

    public function getId(): int
    {
        return $this->id;
    }
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getIdPais(): int
    {
        return $this->id_pais;
    }
    public function setIdPais(int $id_pais): void
    {
        if ($id_pais <= 0) {
            throw new Exception("El país vinculado no es válido.");
        }
        $this->id_pais = $id_pais;
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
