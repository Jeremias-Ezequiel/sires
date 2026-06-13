<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Usuario extends Model
{
    // =====================================================================
    // ATRIBUTOS PRIVADOS
    // =====================================================================
    private int $id;
    private int $id_rol;
    private int $id_localidad;
    private int $id_nacionalidad;
    private int $id_provincia;
    private string $nombre;
    private string $apellido;
    private string $email;
    private string $password;

    // ⚡ ATRIBUTOS DE BAJA LÓGICA E HISTORIAL
    private int $is_active = 1;
    private string $fecha_alta = '';
    private ?string $fecha_baja = null;

    // 🔑 ATRIBUTOS ADICIONALES PARA RECUPERACIÓN DE CONTRASEÑA
    private ?string $reset_token = null;
    private ?string $reset_expires_at = null;

    public const ACTIVE = 1;
    public const INACTIVE = 0;

    // =====================================================================
    // MÉTODOS DE NEGOCIO MÁSTER (FUSIONADOS)
    // =====================================================================

    public function findByEmail(string $email): ?Usuario
    {
        try {
            $cleanEmail = filter_var(trim($email), FILTER_SANITIZE_EMAIL);

            $sql = "SELECT * FROM Usuarios WHERE email = :email";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':email' => $cleanEmail]);

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Usuario::class);
            $user = $stmt->fetch();

            return $user ?: null;
        } catch (PDOException $e) {
            error_log("Error en findByEmail: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar el usuario.");
        }
    }

    public function listEmployees(?string $query, $is_active, $role): array
    {
        $conditions = [];
        $params = [];

        $query = ($query === "") ? null : $query;
        $is_active = ($is_active === "") ? null : $is_active;
        $role = ($role === "") ? null : $role;

        if ($query !== null) {
            $cleanQuery = htmlspecialchars(trim($query), ENT_QUOTES, 'UTF-8');
            $search = "%" . $cleanQuery . "%";

            $conditions[] = "(nombre LIKE :search OR apellido LIKE :search_apellido)";
            $params['search'] = $search;
            $params['search_apellido'] = $search;
        }

        if ($is_active !== null) {
            $conditions[] = "is_active = :is_active";
            $params['is_active'] = (int)$is_active;
        }

        if ($role !== null) {
            $conditions[] = "id_rol = :role";
            $params['role'] = (int)$role;
        }

        $sql = "SELECT * FROM Usuarios";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Usuario::class);

            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Error en findEmployees: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar empleados.");
        }
    }

    /**
     * 🔑 METODO REQUERIDO POR AUTHCONTROLLER (Recuperado)
     */
    public function verifyPassword(string $inputPassword): bool
    {
        return password_verify($inputPassword, $this->password);
    }

    public function save(Usuario $usuario): bool
    {
        try {
            $sql = "INSERT INTO Usuarios (
                        id_rol, id_localidad, id_nacionalidad, id_provincia, nombre, apellido, email, password, is_active
                    ) VALUES (
                        :id_rol, :id_localidad, :id_nacionalidad, :id_provincia, :nombre, :apellido, :email, :password, :is_active
                    )";

            $stmt = $this->db->prepare($sql);

            return $stmt->execute([
                ':id_rol'          => $usuario->getIdRol(),
                ':id_localidad'    => $usuario->getIdLocalidad(),
                ':id_nacionalidad' => $usuario->getIdNacionalidad(),
                ':id_provincia'    => $usuario->getIdProvincia(),
                ':nombre'          => $usuario->getNombre(),
                ':apellido'        => $usuario->getApellido(),
                ':email'           => $usuario->getEmail(),
                ':password'        => $usuario->getPassword(),
                ':is_active'       => $usuario->getIsActive()
            ]);
        } catch (PDOException $e) {
            error_log("Error in Usuario::save: " . $e->getMessage());
            if ($e->getCode() === '23000') {
                throw new Exception("El correo electrónico ya se encuentra registrado.");
            }
            throw new Exception("Error interno al procesar el alta.");
        }
    }

    // =====================================================================
    // ⚙️ MÉTODOS PARA LA RECUPERACIÓN DE CONTRASEÑA (Recuperados)
    // =====================================================================

    /**
     * 🔑 METODO REQUERIDO POR RECOVERYCONTROLLER (Recuperado)
     */
    public function saveResetToken(string $token, string $expiresAt): bool
    {
        try {
            $sql = "UPDATE Usuarios SET reset_token = :token, reset_expires_at = :expires_at WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':token'      => $token,
                ':expires_at' => $expiresAt,
                ':id'         => $this->id
            ]);
        } catch (PDOException $e) {
            error_log("Error en saveResetToken: " . $e->getMessage());
            throw new Exception("Error al guardar el token de verificación.");
        }
    }

    public function findByValidToken(string $token): ?Usuario
    {
        try {
            $sql = "SELECT * FROM Usuarios 
                    WHERE reset_token = :token 
                      AND reset_expires_at > NOW() 
                      AND is_active = 1 
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':token' => $token]);
            
            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Usuario::class);
            $user = $stmt->fetch();
            
            return $user ?: null;
        } catch (PDOException $e) {
            error_log("Error en findByValidToken: " . $e->getMessage());
            throw new Exception("Error al validar el token de acceso.");
        }
    }

    public function updatePasswordAfterReset(string $newPassword): bool
    {
        try {
            if (strlen($newPassword) < 5) {
                throw new Exception("La contraseña debe tener una longitud mínima de 5 caracteres.");
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            
            $sql = "UPDATE Usuarios 
                    SET password = :password, reset_token = NULL, reset_expires_at = NULL 
                    WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':password' => $hashedPassword,
                ':id'       => $this->id
            ]);
        } catch (PDOException $e) {
            error_log("Error en updatePasswordAfterReset: " . $e->getMessage());
            throw new Exception("Error interno al actualizar la clave.");
        }
    }

    // =====================================================================
    // GETTERS Y SETTERS CON LÓGICA DE NEGOCIO Y SANITIZACIÓN STRICT
    // =====================================================================

    public function getId(): int
    {
        return $this->id;
    }
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getIdRol(): int
    {
        return $this->id_rol;
    }
    public function setIdRol(int $id_rol): void
    {
        if ($id_rol <= 0) {
            throw new Exception("El rol especificado no es válido.");
        }
        $this->id_rol = $id_rol;
    }

    public function getIdLocalidad(): int
    {
        return $this->id_localidad;
    }
    public function setIdLocalidad(int $id_localidad): void
    {
        if ($id_localidad <= 0) {
            throw new Exception("La localidad especificada no es válida.");
        }
        $this->id_localidad = $id_localidad;
    }

    public function getIdNacionalidad(): int
    {
        return $this->id_nacionalidad;
    }
    public function setIdNacionalidad(int $id_nacionalidad): void
    {
        if ($id_nacionalidad <= 0) {
            throw new Exception("La nacionalidad especificada no es válida.");
        }
        $this->id_nacionalidad = $id_nacionalidad;
    }

    public function getIdProvincia(): int
    {
        return $this->id_provincia;
    }
    public function setIdProvincia(int $id_provincia): void
    {
        if ($id_provincia <= 0) {
            throw new Exception("La provincia especificada no es válida.");
        }
        $this->id_provincia = $id_provincia;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }
    public function setNombre(string $nombre): void
    {
        $cleanNombre = htmlspecialchars(trim($nombre), ENT_QUOTES, 'UTF-8');
        if (empty($cleanNombre)) {
            throw new Exception("El nombre no puede estar vacío.");
        }
        
        // 🔒 Filtro estricto anti-números en backend
        if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $cleanNombre)) {
            throw new Exception("El nombre solo puede contener letras y espacios.");
        }

        $this->nombre = $cleanNombre;
    }

    public function getApellido(): string
    {
        return $this->apellido;
    }
    public function setApellido(string $apellido): void
    {
        $cleanApellido = htmlspecialchars(trim($apellido), ENT_QUOTES, 'UTF-8');
        if (empty($cleanApellido)) {
            throw new Exception("El apellido no puede estar vacío.");
        }
        
        // 🔒 Filtro estricto anti-números en backend
        if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $cleanApellido)) {
            throw new Exception("El apellido solo puede contener letras y espacios.");
        }

        $this->apellido = $cleanApellido;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
    public function setEmail(string $email): void
    {
        $cleanEmail = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("El formato del correo electrónico no es válido.");
        }
        $this->email = $cleanEmail;
    }

    public function getPassword(): string
    {
        return $this->password;
    }
    public function setPassword(string $password): void
    {
        if (strlen($password) < 5) {
            throw new Exception("La contraseña debe tener una longitud mínima de 5 caracteres.");
        }

        $this->password = password_hash($password, PASSWORD_BCRYPT);
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

    public function getResetToken(): ?string 
    { 
        return $this->reset_token; 
    }
    public function setResetToken(?string $token): void 
    { 
        $this->reset_token = $token; 
    }

    public function getResetExpiresAt(): ?string 
    { 
        return $this->reset_expires_at; 
    }
    public function setResetExpiresAt(?string $expiresAt): void 
    { 
        $this->reset_expires_at = $expiresAt; 
    }
}