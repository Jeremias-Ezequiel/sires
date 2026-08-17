<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class TipoHabitacion extends Model
{
    private int $id;
    private string $descripcion;
    private int $is_active;

    public const SIMPLE = 1;
    public const DOBLE = 2;
    public const SUITE = 3;
    public const MATRIMONIAL = 4;

    public function getAll(): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT id, descripcion, is_active FROM Tipos_Habitacion ORDER BY id ASC");
            $stmt->execute();

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, TipoHabitacion::class);
            $tipos = $stmt->fetchAll();

            return $tipos ?: null;
        } catch (PDOException $e) {
            error_log("Error in getAll tipos habitacion: " . $e->getMessage());
            throw new Exception("Database error during tipos habitacion lookup.");
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

    public function getDescripcion(): string
    {
        return $this->descripcion;
    }
    public function setDescripcion(string $descripcion): void
    {
        $cleanDesc = htmlspecialchars(trim($descripcion), ENT_QUOTES, 'UTF-8');
        if (empty($cleanDesc)) {
            throw new Exception("La descripción del tipo de habitación no puede estar vacía.");
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
}