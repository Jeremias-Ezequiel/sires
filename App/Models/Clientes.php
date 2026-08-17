<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Clientes extends Model
{
    private ?int $id = null;
    private ?int $id_nacionalidad = null;
    private ?int $id_localidad = null;
    private ?int $id_provincia = null;
    private ?string $nombre = null;
    private ?string $apellido = null;
    private ?string $dni_pasaporte = null;
    private ?string $telefono = null;
    private ?string $email = null;
    private ?string $observaciones = null;

    public function getAll(): ?array
    {
        try {
            $sql = "SELECT * FROM Clientes ORDER BY apellido ASC, nombre ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Clientes::class);
            $clientes = $stmt->fetchAll();

            return $clientes ?: null;
        } catch (PDOException $e) {
            error_log("Error in getAll clientes: " . $e->getMessage());
            throw new Exception("Database error during clientes lookup.");
        }
    }

    public function getById(int $id): ?Clientes
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM Clientes WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Clientes::class);
            $cliente = $stmt->fetch();

            return $cliente ?: null;
        } catch (PDOException $e) {
            error_log("Error in getById clientes: " . $e->getMessage());
            throw new Exception("Database error during clientes lookup.");
        }
    }

    public function save(Clientes $cliente): bool
    {
        try {
            $sql = "INSERT INTO Clientes (id_nacionalidad, id_localidad, id_provincia, nombre, apellido, dni, telefono, email, observaciones)
                    VALUES (:id_nacionalidad, :id_localidad, :id_provincia, :nombre, :apellido, :dni, :telefono, :email, :observaciones)";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id_nacionalidad' => $cliente->getIdNacionalidad(),
                ':id_localidad'    => $cliente->getIdLocalidad(),
                ':id_provincia'    => $cliente->getIdProvincia(),
                ':nombre'          => $cliente->getNombre(),
                ':apellido'        => $cliente->getApellido(),
                ':dni'             => $cliente->getDniPasaporte(),
                ':telefono'        => $cliente->getTelefono(),
                ':email'           => $cliente->getEmail(),
                ':observaciones'   => $cliente->getObservaciones()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Clientes::save: " . $e->getMessage());
            throw new Exception("Error interno al registrar el cliente.");
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getIdNacionalidad(): ?int
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

    public function getIdLocalidad(): ?int
    {
        return $this->id_localidad;
    }
    public function setIdLocalidad(int $id_localidad): void
    {
        if ($id_localidad <= 0) {
            throw new Exception("La localidad vinculada no es válida.");
        }
        $this->id_localidad = $id_localidad;
    }

    public function getIdProvincia(): ?int
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

    public function getNombre(): ?string
    {
        return $this->nombre;
    }
    public function setNombre(string $nombre): void
    {
        $clean = htmlspecialchars(trim($nombre), ENT_QUOTES, 'UTF-8');
        if (empty($clean)) {
            throw new Exception("El nombre del cliente no puede estar vacío.");
        }
        $this->nombre = $clean;
    }

    public function getApellido(): ?string
    {
        return $this->apellido;
    }
    public function setApellido(string $apellido): void
    {
        $clean = htmlspecialchars(trim($apellido), ENT_QUOTES, 'UTF-8');
        if (empty($clean)) {
            throw new Exception("El apellido del cliente no puede estar vacío.");
        }
        $this->apellido = $clean;
    }

    public function getDniPasaporte(): ?string
    {
        return $this->dni_pasaporte;
    }
    public function setDniPasaporte(string $dni): void
    {
        $clean = htmlspecialchars(trim($dni_pasaporte), ENT_QUOTES, 'UTF-8');
        if (empty($clean)) {
            throw new Exception("El DNI del cliente no puede estar vacío.");
        }
        $this->dni_pasaporte = $clean;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }
    public function setTelefono(string $telefono): void
    {
        $clean = htmlspecialchars(trim($telefono), ENT_QUOTES, 'UTF-8');
        if (empty($clean)) {
            throw new Exception("El teléfono del cliente no puede estar vacío.");
        }
        $this->telefono = $clean;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }
    public function setEmail(string $email): void
    {
        $clean = htmlspecialchars(trim($email), ENT_QUOTES, 'UTF-8');
        if (empty($clean)) {
            throw new Exception("El email del cliente no puede estar vacío.");
        }
        if (!filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("El formato del email no es válido.");
        }
        $this->email = $clean;
    }

    public function getObservaciones(): ?string
    {
        return $this->observaciones;
    }
    public function setObservaciones(?string $observaciones): void
    {
        if ($observaciones !== null && $observaciones !== '') {
            $this->observaciones = htmlspecialchars(trim($observaciones), ENT_QUOTES, 'UTF-8');
        } else {
            $this->observaciones = null;
        }
    }
}
