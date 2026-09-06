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

    // 📋 ATRIBUTOS PARA REGISTRO DE EMPLEADOS ARGENTINOS
    private ?string $telefono = null;
    private ?string $dni = null;
    private ?string $cuil = null;
    private ?string $direccion = null;
    private ?string $fecha_nacimiento = null;

    // ⚡ ATRIBUTOS DE BAJA LÓGICA E HISTORIAL
    private int $is_active = 1;
    private string $fecha_alta = '';
    private ?string $fecha_baja = null;

    private ?string $rol_descripcion = null;

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
            $cleanEmail = mb_strtolower(trim($email), 'UTF-8');
            $cleanEmail = filter_var($cleanEmail, FILTER_SANITIZE_EMAIL);

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

    public function listEmployees(?string $query, $is_active, $role, int $limit = 10, int $offset = 0): array
    {
        $conditions = [];
        $params = [];

        $query = ($query === "") ? null : $query;
        $is_active = ($is_active === "") ? null : $is_active;
        $role = ($role === "") ? null : $role;

        if ($query !== null) {
            $cleanQuery = htmlspecialchars(trim($query), ENT_QUOTES, 'UTF-8');
            $search = "%" . $cleanQuery . "%";
            $conditions[] = "(u.nombre LIKE :search OR u.apellido LIKE :search_apellido)";
            $params['search'] = $search;
            $params['search_apellido'] = $search;
        }

        if ($is_active !== null) {
            $conditions[] = "u.is_active = :is_active";
            $params['is_active'] = (int)$is_active;
        }

        if ($role !== null) {
            $conditions[] = "u.id_rol = :role";
            $params['role'] = (int)$role;
        }

        // Construcción de la SQL con límites de paginación
        $sql = "SELECT u.*, r.descripcion AS rol_descripcion
                FROM Usuarios u
                LEFT JOIN Roles r ON u.id_rol = r.id";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        
        $sql .= " ORDER BY u.is_active DESC, u.id ASC LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->db->prepare($sql);
            
            // Mapeamos los filtros comunes
            foreach ($params as $key => $val) {
                $stmt->bindValue(':' . $key, $val);
            }
            // Bind estricto como enteros para LIMIT y OFFSET
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Usuario::class);

            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Error en listEmployees con paginación: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar empleados.");
        }
    }

    /**
     * Cuenta la cantidad total de empleados según los filtros activos
     */
    public function countEmployees(?string $query, $is_active, $role): int
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

        $sql = "SELECT COUNT(*) FROM Usuarios";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en countEmployees: " . $e->getMessage());
            return 0;
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
            $check = $this->db->prepare("SELECT COUNT(*) FROM Usuarios WHERE dni = :dni AND dni IS NOT NULL");
            $check->execute([':dni' => $usuario->getDni()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El DNI ya se encuentra registrado.");
            }

            $check = $this->db->prepare("SELECT COUNT(*) FROM Usuarios WHERE cuil = :cuil AND cuil IS NOT NULL");
            $check->execute([':cuil' => $usuario->getCuil()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El CUIL ya se encuentra registrado.");
            }

            $sql = "INSERT INTO Usuarios (
                        id_rol, id_localidad, id_nacionalidad, id_provincia,
                        nombre, apellido, email, telefono, dni, cuil, direccion, fecha_nacimiento,
                        password, is_active
                    ) VALUES (
                        :id_rol, :id_localidad, :id_nacionalidad, :id_provincia,
                        :nombre, :apellido, :email, :telefono, :dni, :cuil, :direccion, :fecha_nacimiento,
                        :password, :is_active
                    )";

            $stmt = $this->db->prepare($sql);

            return $stmt->execute([
                ':id_rol'            => $usuario->getIdRol(),
                ':id_localidad'      => $usuario->getIdLocalidad(),
                ':id_nacionalidad'   => $usuario->getIdNacionalidad(),
                ':id_provincia'      => $usuario->getIdProvincia(),
                ':nombre'            => $usuario->getNombre(),
                ':apellido'          => $usuario->getApellido(),
                ':email'             => $usuario->getEmail(),
                ':telefono'          => $usuario->getTelefono(),
                ':dni'               => $usuario->getDni(),
                ':cuil'              => $usuario->getCuil(),
                ':direccion'         => $usuario->getDireccion(),
                ':fecha_nacimiento'  => $usuario->getFechaNacimiento(),
                ':password'          => $usuario->getPassword(),
                ':is_active'         => $usuario->getIsActive()
            ]);
        } catch (PDOException $e) {
            error_log("Error in Usuario::save: " . $e->getMessage());
            if ($e->getCode() === '23000') {
                throw new Exception("El correo electrónico ya se encuentra registrado.");
            }
            throw new Exception("Error interno al procesar el alta.");
        }
    }

    public function findById(int $id): ?Usuario
    {
        try {
            $sql = "SELECT * FROM Usuarios WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Usuario::class);
            $user = $stmt->fetch();
            return $user ?: null;
        } catch (PDOException $e) {
            error_log("Error en findById: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar el usuario.");
        }
    }

    public function update(Usuario $usuario): bool
    {
        try {
            $check = $this->db->prepare("SELECT COUNT(*) FROM Usuarios WHERE email = :email AND id != :id");
            $check->execute([':email' => $usuario->getEmail(), ':id' => $usuario->getId()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El correo electrónico ya está en uso por otro usuario.");
            }

            $check = $this->db->prepare("SELECT COUNT(*) FROM Usuarios WHERE dni = :dni AND dni IS NOT NULL AND id != :id");
            $check->execute([':dni' => $usuario->getDni(), ':id' => $usuario->getId()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El DNI ya está registrado por otro usuario.");
            }

            $check = $this->db->prepare("SELECT COUNT(*) FROM Usuarios WHERE cuil = :cuil AND cuil IS NOT NULL AND id != :id");
            $check->execute([':cuil' => $usuario->getCuil(), ':id' => $usuario->getId()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El CUIL ya está registrado por otro usuario.");
            }

            $sql = "UPDATE Usuarios SET 
                        id_rol = :id_rol,
                        id_localidad = :id_localidad,
                        id_nacionalidad = :id_nacionalidad,
                        id_provincia = :id_provincia,
                        nombre = :nombre,
                        apellido = :apellido,
                        email = :email,
                        telefono = :telefono,
                        dni = :dni,
                        cuil = :cuil,
                        direccion = :direccion,
                        fecha_nacimiento = :fecha_nacimiento
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id_rol'            => $usuario->getIdRol(),
                ':id_localidad'      => $usuario->getIdLocalidad(),
                ':id_nacionalidad'   => $usuario->getIdNacionalidad(),
                ':id_provincia'      => $usuario->getIdProvincia(),
                ':nombre'            => $usuario->getNombre(),
                ':apellido'          => $usuario->getApellido(),
                ':email'             => $usuario->getEmail(),
                ':telefono'          => $usuario->getTelefono(),
                ':dni'               => $usuario->getDni(),
                ':cuil'              => $usuario->getCuil(),
                ':direccion'         => $usuario->getDireccion(),
                ':fecha_nacimiento'  => $usuario->getFechaNacimiento(),
                ':id'                => $usuario->getId()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::update: " . $e->getMessage());
            if ($e->getCode() === '23000') {
                throw new Exception("El correo electrónico ya se encuentra registrado.");
            }
            throw new Exception("Error interno al actualizar el empleado.");
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
                if (strlen($newPassword) < 8) {
                throw new Exception("La contraseña debe tener al menos 8 caracteres.");
            }
            if (!preg_match('/[A-Z]/', $newPassword)) {
                throw new Exception("La contraseña debe contener al menos una letra mayúscula.");
            }
            if (!preg_match('/\d/', $newPassword)) {
                throw new Exception("La contraseña debe contener al menos un número.");
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
    /**
     * Permite cambiar la clave a un usuario directamente por base de datos (Uso de Dashboard)
     */
    public function changePasswordAdmin(string $newPassword): bool
    {
        try {

            if (strlen($newPassword) < 5) {
                throw new Exception("La contraseña debe tener una longitud mínima de 5 caracteres.");
            }
            if (strlen($newPassword) < 8) {
                throw new Exception("La contraseña debe tener al menos 8 caracteres.");
            }
            if (!preg_match('/[A-Z]/', $newPassword)) {
                throw new Exception("La contraseña debe contener al menos una letra mayúscula.");
            }
            if (!preg_match('/\d/', $newPassword)) {
                throw new Exception("La contraseña debe contener al menos un número.");

            }

            // Encriptamos usando la configuración nativa de tu sistema (BCRYPT)
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            
            $sql = "UPDATE Usuarios SET password = :password WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':password' => $hashedPassword,
                ':id'       => $this->id
            ]);
        } catch (PDOException $e) {
            error_log("Error en changePasswordAdmin: " . $e->getMessage());
            throw new Exception("Error interno en la base de datos al guardar la nueva contraseña.");
        }
    }

// =====================================================================
    // 🛡️ MÉTODOS DE CAMBIO DE ESTADO (BAJA LÓGICA Y REACTIVACIÓN)
    // =====================================================================

    public function deactivate(int $id): bool
    {
        try {
            // Solo actualiza si coincide el ID y además sigue activo
            $sql = "UPDATE Usuarios 
                    SET is_active = :is_inactive, fecha_baja = NOW() 
                    WHERE id = :id AND is_active = :current_active";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':is_inactive'    => self::INACTIVE, // 0
                ':id'             => $id,
                ':current_active' => self::ACTIVE     // 1
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en deactivate: " . $e->getMessage());
            throw new Exception("Error interno en la base de datos.");
        }
    }

    public function activate(int $id): bool
    {
        try {
            // Solo actualiza si coincide el ID y además estaba dado de baja
            $sql = "UPDATE Usuarios 
                    SET is_active = :is_active, fecha_baja = NULL 
                    WHERE id = :id AND is_active = :current_inactive";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':is_active'        => self::ACTIVE,   // 1
                ':id'               => $id,
                ':current_inactive' => self::INACTIVE // 0
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en activate: " . $e->getMessage());
            throw new Exception("Error interno en la base de datos.");
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
        $cleanNombre = trim($nombre);
        if (empty($cleanNombre)) {
            throw new Exception("El nombre no puede estar vacío.");
        }

        $cleanNombre = mb_convert_case($cleanNombre, MB_CASE_TITLE, 'UTF-8');
        $cleanNombre = htmlspecialchars($cleanNombre, ENT_QUOTES, 'UTF-8');

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
        $cleanApellido = trim($apellido);
        if (empty($cleanApellido)) {
            throw new Exception("El apellido no puede estar vacío.");
        }

        $cleanApellido = mb_convert_case($cleanApellido, MB_CASE_TITLE, 'UTF-8');
        $cleanApellido = htmlspecialchars($cleanApellido, ENT_QUOTES, 'UTF-8');

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
        $cleanEmail = mb_strtolower(trim($email), 'UTF-8');
        $cleanEmail = filter_var($cleanEmail, FILTER_SANITIZE_EMAIL);
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
        if (strlen($password) < 8) {
            throw new Exception("La contraseña debe tener al menos 8 caracteres.");
        }
        if (!preg_match('/[A-Z]/', $password)) {
            throw new Exception("La contraseña debe contener al menos una letra mayúscula.");
        }
        if (!preg_match('/\d/', $password)) {
            throw new Exception("La contraseña debe contener al menos un número.");

        }

        $this->password = password_hash($password, PASSWORD_BCRYPT);
    }

    // =====================================================================
    // GETTERS Y SETTERS PARA CAMPOS DE EMPLEADOS ARGENTINOS
    // =====================================================================

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }
    public function setTelefono(?string $telefono): void
    {
        $clean = trim($telefono ?? '');
        if ($clean !== '') {
            $digits = preg_replace('/\D/', '', $clean);
            if (strlen($digits) < 8) {
                throw new Exception("El teléfono debe tener al menos 8 dígitos.");
            }
            if (!preg_match('/^[0-9+\-\s()]{10,50}$/', $clean)) {
                throw new Exception("El formato del teléfono no es válido.");
            }
        }
        $this->telefono = $clean !== '' ? $clean : null;
    }

    public function getDni(): ?string
    {
        return $this->dni;
    }
    public function setDni(?string $dni): void
    {
        $clean = trim($dni ?? '');
        if ($clean !== '' && !preg_match('/^[0-9.]{6,20}$/', $clean)) {
            throw new Exception("El formato del DNI no es válido.");
        }
        $this->dni = $clean !== '' ? $clean : null;
    }

    public function getCuil(): ?string
    {
        return $this->cuil;
    }
    public function setCuil(?string $cuil): void
    {
        $clean = trim($cuil ?? '');
        if ($clean !== '') {
            $digits = preg_replace('/\D/', '', $clean);
            if (strlen($digits) !== 11) {
                throw new Exception("El CUIL debe tener 11 dígitos en el formato XX-XXXXXXXX-X.");
            }
            $this->cuil = substr($digits, 0, 2) . '-' . substr($digits, 2, 8) . '-' . substr($digits, 10, 1);
        } else {
            $this->cuil = null;
        }
    }

    public function getDireccion(): ?string
    {
        return $this->direccion;
    }
    public function setDireccion(?string $direccion): void
    {
        $clean = trim($direccion ?? '');
        if ($clean !== '') {
            $clean = htmlspecialchars($clean, ENT_QUOTES, 'UTF-8');
        }
        $this->direccion = $clean !== '' ? $clean : null;
    }

    public function getFechaNacimiento(): ?string
    {
        return $this->fecha_nacimiento;
    }
    public function setFechaNacimiento(?string $fecha_nacimiento): void
    {
        $clean = trim($fecha_nacimiento ?? '');
        if ($clean !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean)) {
            throw new Exception("El formato de la fecha de nacimiento no es válido (AAAA-MM-DD).");
        }
        if ($clean !== '') {
            $nacimiento = new \DateTime($clean);
            $hoy = new \DateTime();
            $edad = $hoy->diff($nacimiento)->y;
            if ($edad < 18) {
                throw new Exception("El usuario debe ser mayor de edad (18 años o más).");
            }
            if ($edad > 130) {
                throw new Exception("La fecha de nacimiento indica una edad mayor a 130 años.");
            }
        }
        $this->fecha_nacimiento = $clean !== '' ? $clean : null;
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
        if ($fecha_baja !== null && $this->fecha_alta !== '' && $fecha_baja < $this->fecha_alta) {
            throw new Exception("La fecha de baja no puede ser anterior a la fecha de alta.");
        }
        $this->fecha_baja = $fecha_baja;
    }

    public function getRolDescripcion(): ?string
    {
        return $this->rol_descripcion;
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
