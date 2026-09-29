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

    // 🔒 ATRIBUTOS DE PROTECCIÓN CONTRA FUERZA BRUTA
    // Con defaults para que el objeto siga siendo usable si el SELECT no trae
    // la columna (base vieja a medio migrar): sin default, un getter sobre una
    // propiedad tipada sin inicializar revienta con Error.
    private int $intentos_fallidos = 0;
    private ?string $bloqueado_hasta = null;
    private ?string $ultimo_acceso = null;
    private ?string $ultimo_ip = null;

    // Estado del bloqueo, calculado por MariaDB y no por PHP. Son alias del
    // SELECT, no columnas reales: ver la nota de zona horaria en refrescarBloqueo().
    private int $lock_activo = 0;
    private int $lock_segundos = 0;

    public const ACTIVE = 1;
    public const INACTIVE = 0;

    /** Fallos seguidos que bloquean la cuenta. */
    public const MAX_INTENTOS_LOGIN = 5;

    /** Minutos de bloqueo al pasarse. */
    public const MINUTOS_BLOQUEO_LOGIN = 15;

    // =====================================================================
    // MÉTODOS DE NEGOCIO MÁSTER (FUSIONADOS)
    // =====================================================================

    public function findByEmail(string $email): ?Usuario
    {
        try {
            $cleanEmail = mb_strtolower(trim($email), 'UTF-8');
            $cleanEmail = filter_var($cleanEmail, FILTER_SANITIZE_EMAIL);

            // Los dos ultimos campos no son columnas: los calcula la base para
            // responder "esta bloqueado?" sin mezclar el reloj de MariaDB con el
            // de PHP. Van en el SELECT para que el objeto que vuelve ya venga
            // con el estado listo y nadie tenga que acordarse de refrescarlo.
            $sql = "SELECT *,
                           CASE WHEN bloqueado_hasta IS NOT NULL
                                     AND bloqueado_hasta > NOW() THEN 1 ELSE 0 END AS lock_activo,
                           CASE WHEN bloqueado_hasta IS NOT NULL
                                     AND bloqueado_hasta > NOW()
                                THEN TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta)
                                ELSE 0 END AS lock_segundos
                      FROM Usuarios
                     WHERE email = :email";
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

        if ($role !== null) {
            $conditions[] = "u.id_rol = :role";
            $params['role'] = (int)$role;
        }

        if ($is_active !== null) {
            $conditions[] = "u.is_active = :is_active";
            $params['is_active'] = (int)$is_active;
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

        if ($role !== null) {
            $conditions[] = "id_rol = :role";
            $params['role'] = (int)$role;
        }

        if ($is_active !== null) {
            $conditions[] = "is_active = :is_active";
            $params['is_active'] = (int)$is_active;
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

    /**
     * El hash guardado, para que el controlador pueda correr el mismo
     * password_verify contra un hash falso cuando el mail no existe y asi no
     * delatar por tiempo de respuesta que cuentas estan registradas.
     */
    public function getPasswordHash(): string
    {
        return $this->password;
    }

    // =====================================================================
    // 🔒 PROTECCIÓN CONTRA FUERZA BRUTA
    // =====================================================================

    /**
     * Cuenta los fallos y bloquea al llegar al limite, en una sola sentencia.
     *
     * El contador va en un subquery a proposito. En un UPDATE de una sola tabla
     * MySQL evalua las asignaciones de izquierda a derecha y la segunda ve el
     * valor nuevo de la primera, asi que escribir
     * "intentos_fallidos = intentos_fallidos + 1" y despues comparar
     * "intentos_fallidos >= 5" en la misma sentencia contaria doble y
     * bloquearia en el quinto fallo cuando la idea era en el quinto, con el
     * numero corrido. Calculando el valor una vez en la subquery, el orden de
     * evaluacion deja de importar y dos intentos simultaneos no se pisan.
     *
     * Si el bloqueo ya vencio, la cuenta arranca de nuevo en 1. Si el contador
     * queda por debajo del limite, bloqueado_hasta se limpia para no dejar un
     * sello viejo de una cuenta que nunca llego a bloquearse.
     */
    public function registrarFalloLogin(): void
    {
        try {
            $sql = "UPDATE Usuarios u
                    JOIN (
                        SELECT id,
                               CASE
                                   WHEN bloqueado_hasta IS NOT NULL
                                        AND bloqueado_hasta <= NOW() THEN 1
                                   ELSE intentos_fallidos + 1
                               END AS nuevos
                          FROM Usuarios
                         WHERE id = :id
                    ) n ON n.id = u.id
                    SET u.intentos_fallidos = n.nuevos,
                        u.bloqueado_hasta  = CASE
                                                WHEN n.nuevos >= :maximo
                                                    THEN DATE_ADD(NOW(), INTERVAL :minutos MINUTE)
                                                ELSE NULL
                                            END
                    WHERE u.id = :id2";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id', $this->getId(), \PDO::PARAM_INT);
            $stmt->bindValue(':id2', $this->getId(), \PDO::PARAM_INT);
            $stmt->bindValue(':maximo', self::MAX_INTENTOS_LOGIN, \PDO::PARAM_INT);
            $stmt->bindValue(':minutos', self::MINUTOS_BLOQUEO_LOGIN, \PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("No se pudo registrar el fallo de login: " . $e->getMessage());
        }
    }

    /**
     * Deja la cuenta desbloqueada. Se llama en cada login exitoso.
     */
    public function resetIntentosLogin(): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE Usuarios
                                        SET intentos_fallidos = 0, bloqueado_hasta = NULL
                                        WHERE id = :id");
            $stmt->bindValue(':id', $this->getId(), \PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("No se pudieron resetear los intentos de login: " . $e->getMessage());
        }
    }

    /**
     * Anota el acceso efectivo con fecha e IP. Va junto al reset porque las dos
     * cosas pasan en el mismo instante.
     */
    public function registrarAcceso(?string $ip): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE Usuarios
                                        SET ultimo_acceso = NOW(), ultimo_ip = :ip
                                        WHERE id = :id");
            $stmt->bindValue(':ip', $ip);
            $stmt->bindValue(':id', $this->getId(), \PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("No se pudo registrar el acceso: " . $e->getMessage());
        }
    }

    public function getIntentosFallidos(): int
    {
        return $this->intentos_fallidos;
    }

    public function getBloqueadoHasta(): ?string
    {
        return $this->bloqueado_hasta;
    }

    public function getUltimoAcceso(): ?string
    {
        return $this->ultimo_acceso;
    }

    public function getUltimoIp(): ?string
    {
        return $this->ultimo_ip;
    }

    /**
     * Si la cuenta esta bloqueada ahora.
     *
     * La respuesta la da la base, no la comparacion con date(). En esta
     * maquina MariaDB va 5 horas atras de PHP (NOW() 15:09 contra date()
     * 20:09, porque el servidor de base esta en UTC-3 y PHP en Europe/Berlin),
     * y bloqueado_hasta lo escribe un NOW() de la base. Si PHP lo comparara
     * contra su propio reloj, el sello caeria siempre en el pasado y el
     * bloqueo no se activaria nunca. Es un fallo silencioso: el throttle
     * pareceria puesto y no frenaria a nadie.
     */
    public function estaBloqueado(): bool
    {
        return $this->lock_activo === 1;
    }

    /**
     * Minutos que faltan para que se libere el bloqueo, redondeados hacia
     * arriba. 0 si no esta bloqueada.
     */
    public function minutosRestantesBloqueo(): int
    {
        if (!$this->estaBloqueado()) {
            return 0;
        }

        return max(1, (int) ceil($this->lock_segundos / 60));
    }

    /**
     * Relee intentos y bloqueo, y recalcula en SQL si el bloqueo sigue vigente
     * y cuanto le queda. Se llama despues de un lookup para no decidir con una
     * copia vieja.
     */
    public function refrescarBloqueo(): void
    {
        try {
            $sql = "SELECT intentos_fallidos,
                           bloqueado_hasta,
                           ultimo_acceso,
                           ultimo_ip,
                           CASE WHEN bloqueado_hasta IS NOT NULL
                                     AND bloqueado_hasta > NOW() THEN 1 ELSE 0 END AS lock_activo,
                           CASE WHEN bloqueado_hasta IS NOT NULL
                                     AND bloqueado_hasta > NOW()
                                THEN TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta)
                                ELSE 0 END AS lock_segundos
                      FROM Usuarios
                     WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id', $this->getId(), \PDO::PARAM_INT);
            $stmt->execute();
            $fila = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($fila === false) {
                return;
            }

            $this->intentos_fallidos = (int) $fila['intentos_fallidos'];
            $this->bloqueado_hasta   = $fila['bloqueado_hasta'] !== null
                ? (string) $fila['bloqueado_hasta']
                : null;
            $this->ultimo_acceso     = $fila['ultimo_acceso'] !== null
                ? (string) $fila['ultimo_acceso']
                : null;
            $this->ultimo_ip         = $fila['ultimo_ip'] !== null
                ? (string) $fila['ultimo_ip']
                : null;
            $this->lock_activo       = (int) $fila['lock_activo'];
            $this->lock_segundos     = (int) $fila['lock_segundos'];
        } catch (PDOException $e) {
            error_log("No se pudo refrescar el bloqueo: " . $e->getMessage());
        }
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

            $this->validarPassword($newPassword);

            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            
            // El desbloqueo va en la misma sentencia, a proposito: si el login
            // quedo bloqueado por intentos fallidos, la persona que Loguea
            // recupera la clave por mail y al volver a entrar seguira trabada
            // hasta que se le pase el plazo, sin entender por que. Aprovechar
            // que demostro tener el mail de la cuenta es prueba suficiente.
            $sql = "UPDATE Usuarios 
                    SET password = :password, 
                        reset_token = NULL, 
                        reset_expires_at = NULL,
                        intentos_fallidos = 0,
                        bloqueado_hasta = NULL
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

            $this->validarPassword($newPassword);

            // Encriptamos usando la configuración nativa de tu sistema (BCRYPT)
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            
            // Mismo criterio que en la recuperación: si un administrador
            // redefine la clave, la cuenta queda usable de una, sin esperar
            // que expire el bloqueo por intentos fallidos.
            $sql = "UPDATE Usuarios
                    SET password = :password,
                        intentos_fallidos = 0,
                        bloqueado_hasta = NULL
                    WHERE id = :id";
            
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
        $this->validarPassword($password);
        $this->password = password_hash($password, PASSWORD_BCRYPT);
    }

    private function validarPassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new Exception("La contraseña debe tener al menos 8 caracteres.");
        }
        if (!preg_match('/[A-Z]/', $password)) {
            throw new Exception("La contraseña debe contener al menos una letra mayúscula.");
        }
        if (!preg_match('/\d/', $password)) {
            throw new Exception("La contraseña debe contener al menos un número.");
        }
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

    /**
     * Obtiene todos los usuarios con un rol específico.
     */
    public function getByRole(int $idRol): array
    {
        try {
            $sql = "SELECT id, nombre, apellido, email, telefono, is_active
                    FROM Usuarios
                    WHERE id_rol = :id_rol
                    ORDER BY apellido ASC, nombre ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id_rol' => $idRol]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Usuario::getByRole: " . $e->getMessage());
            throw new Exception("Error al buscar usuarios por rol.");
        }
    }
}
