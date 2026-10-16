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
    private ?int $is_active = 1;

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

    public function listClientes(?string $query, $is_active, int $limit = 10, int $offset = 0): ?array
    {
        $conditions = [];
        $params = [];

        $query = ($query === "") ? null : $query;

        if ($query !== null) {
            $cleanQuery = htmlspecialchars(trim($query), ENT_QUOTES, 'UTF-8');
            $search = "%" . $cleanQuery . "%";
            $conditions[] = "(nombre LIKE :search_nombre OR apellido LIKE :search_apellido OR dni_pasaporte LIKE :search_dni)";
            $params['search_nombre'] = $search;
            $params['search_apellido'] = $search;
            $params['search_dni'] = $search;
        }

        $sql = "SELECT * FROM Clientes";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        $sql .= " ORDER BY apellido ASC, nombre ASC LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue(':' . $key, $val);
            }
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Clientes::class);
            return $stmt->fetchAll() ?: null;
        } catch (PDOException $e) {
            error_log("Error en Clientes::listClientes: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar clientes.");
        }
    }

    public function countClientes(?string $query, $is_active): int
    {
        $conditions = [];
        $params = [];

        $query = ($query === "") ? null : $query;

        if ($query !== null) {
            $cleanQuery = htmlspecialchars(trim($query), ENT_QUOTES, 'UTF-8');
            $search = "%" . $cleanQuery . "%";
            $conditions[] = "(nombre LIKE :search_nombre OR apellido LIKE :search_apellido OR dni_pasaporte LIKE :search_dni)";
            $params['search_nombre'] = $search;
            $params['search_apellido'] = $search;
            $params['search_dni'] = $search;
        }

        $sql = "SELECT COUNT(*) FROM Clientes";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Clientes::countClientes: " . $e->getMessage());
            return 0;
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
            $check = $this->db->prepare("SELECT COUNT(*) FROM Clientes WHERE dni_pasaporte = :dni");
            $check->execute([':dni' => $cliente->getDniPasaporte()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El DNI/Pasaporte ya se encuentra registrado.");
            }

            $check = $this->db->prepare("SELECT COUNT(*) FROM Clientes WHERE email = :email");
            $check->execute([':email' => $cliente->getEmail()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El email ya se encuentra registrado.");
            }

            $sql = "INSERT INTO Clientes (id_nacionalidad, id_localidad, id_provincia, nombre, apellido, dni_pasaporte, telefono, email, observaciones)
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
                ':email'            => $cliente->getEmail(),
                ':observaciones'   => $cliente->getObservaciones()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Clientes::save: " . $e->getMessage());
            if ($e->getCode() === '23000') {
                throw new Exception("El DNI o email ya se encuentra registrado.");
            }
            throw new Exception("Error interno al registrar el cliente.");
        }
    }

    public function update(Clientes $cliente): bool
    {
        try {
            $check = $this->db->prepare("SELECT COUNT(*) FROM Clientes WHERE dni_pasaporte = :dni AND id != :id");
            $check->execute([':dni' => $cliente->getDniPasaporte(), ':id' => $cliente->getId()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El DNI/Pasaporte ya se encuentra registrado por otro cliente.");
            }

            $check = $this->db->prepare("SELECT COUNT(*) FROM Clientes WHERE email = :email AND id != :id");
            $check->execute([':email' => $cliente->getEmail(), ':id' => $cliente->getId()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El email ya se encuentra registrado por otro cliente.");
            }

            $sql = "UPDATE Clientes SET
                        id_nacionalidad = :id_nacionalidad,
                        id_localidad    = :id_localidad,
                        id_provincia    = :id_provincia,
                        nombre          = :nombre,
                        apellido        = :apellido,
                        dni_pasaporte   = :dni,
                        telefono        = :telefono,
                        email            = :email,
                        observaciones   = :observaciones
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id_nacionalidad' => $cliente->getIdNacionalidad(),
                ':id_localidad'    => $cliente->getIdLocalidad(),
                ':id_provincia'    => $cliente->getIdProvincia(),
                ':nombre'          => $cliente->getNombre(),
                ':apellido'        => $cliente->getApellido(),
                ':dni'             => $cliente->getDniPasaporte(),
                ':telefono'        => $cliente->getTelefono(),
                ':email'            => $cliente->getEmail(),
                ':observaciones'   => $cliente->getObservaciones(),
                ':id'              => $cliente->getId()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Clientes::update: " . $e->getMessage());
            if ($e->getCode() === '23000') {
                throw new Exception("El DNI o email ya se encuentra registrado.");
            }
            throw new Exception("Error interno al actualizar el cliente.");
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
    public function setIdLocalidad(?int $id_localidad): void
    {
        if ($id_localidad !== null && $id_localidad <= 0) {
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
        $clean = \App\Helpers\Validator::letrasYEspacios($nombre, 'nombre', \App\Helpers\Validator::MAX_NOMBRE);
        $this->nombre = mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
    }

    public function getApellido(): ?string
    {
        return $this->apellido;
    }
    public function setApellido(string $apellido): void
    {
        $clean = \App\Helpers\Validator::letrasYEspacios($apellido, 'apellido', \App\Helpers\Validator::MAX_APELLIDO);
        $this->apellido = mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
    }

    public function getDniPasaporte(): ?string
    {
        return $this->dni_pasaporte;
    }
    public function setDniPasaporte(string $dni): void
    {
        $this->dni_pasaporte = \App\Helpers\Validator::documentoCliente($dni);
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }
    public function setTelefono(string $telefono): void
    {
        $this->telefono = \App\Helpers\Validator::telefonoE164((string)$telefono);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }
    public function setEmail(string $email): void
    {
        $this->email = \App\Helpers\Validator::emailValido($email);
    }

    public function getObservaciones(): ?string
    {
        return $this->observaciones;
    }
    public function setObservaciones(?string $observaciones): void
    {
        $this->observaciones = \App\Helpers\Validator::observacionesValidas((string)$observaciones);
    }

    public function getIsActive(): ?int
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
