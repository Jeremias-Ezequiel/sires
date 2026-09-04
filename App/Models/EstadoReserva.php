<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class EstadoReserva extends Model
{
    private int $id;
    private string $descripcion;

    public const PENDIENTE = 1;
    public const CONFIRMADA = 2;
    public const CANCELADA = 3;
    public const FINALIZADA_CHECKOUT = 4;

    public function getAll(): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT id, descripcion FROM Estados_Reserva ORDER BY id ASC");
            $stmt->execute();

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, EstadoReserva::class);
            $estados = $stmt->fetchAll();

            return $estados ?: null;
        } catch (PDOException $e) {
            error_log("Error in getAll estados reserva: " . $e->getMessage());
            throw new Exception("Database error during estados reserva lookup.");
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
            throw new Exception("La descripción del estado de reserva no puede estar vacía.");
        }
        $this->descripcion = $cleanDesc;
    }
}