<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Rol extends Model
{
    // =====================================================================
    // ATRIBUTOS PRIVADOS (Actualizados con la nueva base de datos)
    // =====================================================================
    private int $id;
    private string $descripcion;
    private int $is_active; // ⚡ Flag de control para habilitar/deshabilitar roles

    // Constantes de negocio de SIRES (Se mantienen idénticas y firmes)
    public const ADMINISTRADOR = 1;
    public const RECEPCIONISTA = 2;
    public const GERENTE = 3;
    public const AUDITOR = 4;

    // =====================================================================
    // MÉTODOS DE NEGOCIO
    // =====================================================================

    /**
     * Recupera los roles del sistema.
     * @param bool $onlyActive Si es true, filtra solo los roles habilitados para nuevos usuarios.
     */
    public function getAll(bool $onlyActive = true): ?array
    {
        try {
            $sql = "SELECT id, descripcion, is_active FROM Roles";
            if ($onlyActive) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY id ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            $stmt->setFetchMode(
                PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE,
                Rol::class,
            );
            $roles = $stmt->fetchAll();

            return $roles ?: null;
        } catch (PDOException $e) {
            error_log("Error in getAll roles: " . $e->getMessage());
            throw new Exception("Database error during roles lookup.");
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
        $cleanDesc = htmlspecialchars(trim($descripcion), ENT_QUOTES, "UTF-8");
        if (empty($cleanDesc)) {
            throw new Exception("La descripción del rol no puede estar vacía.");
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
