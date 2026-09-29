<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Impuesto extends Model
{
    private int $id = 0;
    private string $nombre = '';
    private float $porcentaje = 0.0;
    private string $tipo = 'IVA';
    private bool $is_active = true;

    public const TIPO_IVA = 'IVA';
    public const TIPO_TURISMO = 'TURISMO';
    public const TIPO_CITY_TAX = 'CITY_TAX';
    public const TIPO_OTRO = 'OTRO';

    public function getAll(): array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM Impuestos WHERE is_active = 1 ORDER BY nombre ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Impuesto::getAll: " . $e->getMessage());
            return [];
        }
    }

    public function findById(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM Impuestos WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Error en Impuesto::findById: " . $e->getMessage());
            return null;
        }
    }

    public function save(Impuesto $impuesto): bool
    {
        try {
            $sql = "INSERT INTO Impuestos (nombre, porcentaje, tipo, is_active) VALUES (:nombre, :porcentaje, :tipo, :is_active)";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':nombre' => $impuesto->getNombre(),
                ':porcentaje' => $impuesto->getPorcentaje(),
                ':tipo' => $impuesto->getTipo(),
                ':is_active' => $impuesto->isActive() ? 1 : 0
            ]);
        } catch (PDOException $e) {
            error_log("Error en Impuesto::save: " . $e->getMessage());
            return false;
        }
    }

    public function update(Impuesto $impuesto): bool
    {
        try {
            $sql = "UPDATE Impuestos SET nombre = :nombre, porcentaje = :porcentaje, tipo = :tipo, is_active = :is_active WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id' => $impuesto->getId(),
                ':nombre' => $impuesto->getNombre(),
                ':porcentaje' => $impuesto->getPorcentaje(),
                ':tipo' => $impuesto->getTipo(),
                ':is_active' => $impuesto->isActive() ? 1 : 0
            ]);
        } catch (PDOException $e) {
            error_log("Error en Impuesto::update: " . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE Impuestos SET is_active = 0 WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Impuesto::delete: " . $e->getMessage());
            return false;
        }
    }

    public function getId(): int { return $this->id; }
    public function setId(int $id): void { $this->id = $id; }

    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): void { $this->nombre = $nombre; }

    public function getPorcentaje(): float { return $this->porcentaje; }
    public function setPorcentaje(float $porcentaje): void { $this->porcentaje = $porcentaje; }

    public function getTipo(): string { return $this->tipo; }
    public function setTipo(string $tipo): void { $this->tipo = $tipo; }

    public function isActive(): bool { return $this->is_active; }
    public function setIsActive(bool $is_active): void { $this->is_active = $is_active; }
}
