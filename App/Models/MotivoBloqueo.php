<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class MotivoBloqueo extends Model
{
    private int $id;
    private string $descripcion;

    public const REPARACION_PLOMERIA = 1;
    public const PINTURA = 2;
    public const DESINFECCION = 3;
    public const FALLA_ELECTRICA = 4;

    public function getAll(): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT id, descripcion FROM Motivos_Bloqueo ORDER BY id ASC");
            $stmt->execute();

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, MotivoBloqueo::class);
            $motivos = $stmt->fetchAll();

            return $motivos ?: null;
        } catch (PDOException $e) {
            error_log("Error in getAll motivos bloqueo: " . $e->getMessage());
            throw new Exception("Database error during motivos bloqueo lookup.");
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
            throw new Exception("La descripción del motivo de bloqueo no puede estar vacía.");
        }
        $this->descripcion = $cleanDesc;
    }
}