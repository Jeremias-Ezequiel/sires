<?php

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;
use PDOException;
use Exception;

class Habitacion extends Model
{
    private int $id;
    private int $numero;
    private int $piso;
    private int $id_tipo_habitacion;
    private int $id_estado_habitacion;
    private float $precio_noche_base;
    private string $descripcion;

    // Estados de habitación según Estados_Habitacion
    public const ESTADO_DISPONIBLE = 1;
    public const ESTADO_OCUPADA = 2;
    public const ESTADO_MANTENIMIENTO = 3;
    public const ESTADO_BLOQUEADA = 4;

    // La capacidad de cada tipo de habitación vive en Tipos_Habitacion.capacidad.
    // Antes estaba en el mapa TIPOS_POR_CAPACIDAD de esta clase, lo que hacia
    // imposible cambiar la capacidad de un tipo sin tocar y desplegar codigo:
    // cambiar "Suite" de 4 a 5 personas exigia modificar el fuente.
    // Regla de negocio: descuento por ocupación según capacidad y cantidad de huéspedes (%)
    public const DESCUENTOS_POR_CAPACIDAD = [
        4 => [1 => 30, 2 => 20, 3 => 10, 4 => 0],
        3 => [1 => 25, 2 => 10, 3 => 0],
        2 => [1 => 15, 2 => 0],
    ];

    /**
     * Cache de tipos por capacidad y capacidad por tipo.
     *
     * Estas consultas se disparan dentro de listados y del calculo de precios,
     * una vez por habitacion y una vez por linea de detalle. Sin cache, cargar
     * el panel con 50 habitaciones serian 50 idas a la base por lo mismo.
     *
     * Es estatico a proposito: los metodos que la usan son estaticos porque se
     * llaman desde la Vista de precio sin instanciar el modelo.
     *
     * @var array<int, int[]>
     */
    private static array $cacheTiposPorCapacidad = [];

    /** @var array<int, int> */
    private static array $cacheCapacidadPorTipo = [];

    public function getAllWithFilters(?string $search, ?string $status, ?string $type, ?string $floor): array
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $conditions[] = "h.numero LIKE :search";
            $params['search'] = "%" . $search . "%";
        }

        if ($status !== null && $status !== '') {
            $conditions[] = "h.id_estado_habitacion = :status";
            $params['status'] = (int)$status;
        }

        if ($type !== null && $type !== '') {
            $conditions[] = "h.id_tipo_habitacion = :type";
            $params['type'] = (int)$type;
        }

        if ($floor !== null && $floor !== '') {
            $conditions[] = "h.piso = :floor";
            $params['floor'] = (int)$floor;
        }

        // Verificar si el campo descripcion existe (compatibilidad con versiones anteriores)
        $hasDescripcion = false;
        try {
            $checkStmt = $this->db->query("SHOW COLUMNS FROM Habitaciones LIKE 'descripcion'");
            $hasDescripcion = $checkStmt->fetch() !== false;
        } catch (Exception $e) {
            $hasDescripcion = false;
        }

        $descripcionField = $hasDescripcion ? "h.descripcion" : "NULL AS descripcion";
        $sql = "SELECT h.id, h.numero, h.piso, h.precio_noche_base, {$descripcionField},
                       th.descripcion AS tipo, eh.descripcion AS estado,
                       h.id_tipo_habitacion, h.id_estado_habitacion
                FROM Habitaciones h
                JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                JOIN Estados_Habitacion eh ON h.id_estado_habitacion = eh.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY h.numero ASC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getAllWithFilters: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar habitaciones.");
        }
    }

    public function getTiposHabitacion(): array
    {
        try {
            $stmt = $this->db->query("SELECT id, descripcion FROM Tipos_Habitacion ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getTiposHabitacion: " . $e->getMessage());
            return [];
        }
    }

    public function getEstadosHabitacion(): array
    {
        try {
            $stmt = $this->db->query("SELECT id, descripcion FROM Estados_Habitacion ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getEstadosHabitacion: " . $e->getMessage());
            return [];
        }
    }

    public function getPisos(): array
    {
        try {
            $stmt = $this->db->query("SELECT DISTINCT piso FROM Habitaciones ORDER BY piso ASC");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getPisos: " . $e->getMessage());
            return [];
        }
    }

    public function save(Habitacion $habitacion): bool
    {
        try {
            $check = $this->db->prepare("SELECT COUNT(*) FROM Habitaciones WHERE numero = :numero");
            $check->execute([':numero' => $habitacion->getNumero()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El número de habitación ya existe en el sistema.");
            }

            // Verificar si el campo descripcion existe
            $hasDescripcion = false;
            try {
                $checkStmt = $this->db->query("SHOW COLUMNS FROM Habitaciones LIKE 'descripcion'");
                $hasDescripcion = $checkStmt->fetch() !== false;
            } catch (Exception $e) {
                $hasDescripcion = false;
            }

            if ($hasDescripcion) {
                $sql = "INSERT INTO Habitaciones (numero, piso, id_tipo_habitacion, id_estado_habitacion, precio_noche_base, descripcion)
                        VALUES (:numero, :piso, :id_tipo_habitacion, :id_estado_habitacion, :precio_noche_base, :descripcion)";
                $params = [
                    ':numero'               => $habitacion->getNumero(),
                    ':piso'                 => $habitacion->getPiso(),
                    ':id_tipo_habitacion'   => $habitacion->getIdTipoHabitacion(),
                    ':id_estado_habitacion' => $habitacion->getIdEstadoHabitacion(),
                    ':precio_noche_base'    => $habitacion->getPrecioNocheBase(),
                    ':descripcion'          => $habitacion->getDescripcion()
                ];
            } else {
                $sql = "INSERT INTO Habitaciones (numero, piso, id_tipo_habitacion, id_estado_habitacion, precio_noche_base)
                        VALUES (:numero, :piso, :id_tipo_habitacion, :id_estado_habitacion, :precio_noche_base)";
                $params = [
                    ':numero'               => $habitacion->getNumero(),
                    ':piso'                 => $habitacion->getPiso(),
                    ':id_tipo_habitacion'   => $habitacion->getIdTipoHabitacion(),
                    ':id_estado_habitacion' => $habitacion->getIdEstadoHabitacion(),
                    ':precio_noche_base'    => $habitacion->getPrecioNocheBase()
                ];
            }

            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error en Habitacion::save: " . $e->getMessage());
            throw new Exception("Error interno al registrar la habitación.");
        }
    }

    public function findById(int $id): ?array
    {
        try {
            // Verificar si el campo descripcion existe
            $hasDescripcion = false;
            try {
                $checkStmt = $this->db->query("SHOW COLUMNS FROM Habitaciones LIKE 'descripcion'");
                $hasDescripcion = $checkStmt->fetch() !== false;
            } catch (Exception $e) {
                $hasDescripcion = false;
            }

            $descripcionField = $hasDescripcion ? "h.descripcion" : "NULL AS descripcion";
            $sql = "SELECT h.id, h.numero, h.piso, h.precio_noche_base, {$descripcionField},
                           th.descripcion AS tipo, eh.descripcion AS estado,
                           h.id_tipo_habitacion, h.id_estado_habitacion
                    FROM Habitaciones h
                    JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                    JOIN Estados_Habitacion eh ON h.id_estado_habitacion = eh.id
                    WHERE h.id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::findById: " . $e->getMessage());
            throw new Exception("Error interno al buscar la habitación.");
        }
    }

    public function update(Habitacion $habitacion): bool
    {
        try {
            $check = $this->db->prepare("SELECT COUNT(*) FROM Habitaciones WHERE numero = :numero AND id != :id");
            $check->execute([':numero' => $habitacion->getNumero(), ':id' => $habitacion->getId()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("El número de habitación ya está en uso por otra habitación.");
            }

            // Verificar si el campo descripcion existe
            $hasDescripcion = false;
            try {
                $checkStmt = $this->db->query("SHOW COLUMNS FROM Habitaciones LIKE 'descripcion'");
                $hasDescripcion = $checkStmt->fetch() !== false;
            } catch (Exception $e) {
                $hasDescripcion = false;
            }

            if ($hasDescripcion) {
                $sql = "UPDATE Habitaciones
                        SET numero = :numero, piso = :piso,
                            id_tipo_habitacion = :id_tipo_habitacion,
                            id_estado_habitacion = :id_estado_habitacion,
                            precio_noche_base = :precio_noche_base,
                            descripcion = :descripcion
                        WHERE id = :id";
                $params = [
                    ':id'                   => $habitacion->getId(),
                    ':numero'               => $habitacion->getNumero(),
                    ':piso'                 => $habitacion->getPiso(),
                    ':id_tipo_habitacion'   => $habitacion->getIdTipoHabitacion(),
                    ':id_estado_habitacion' => $habitacion->getIdEstadoHabitacion(),
                    ':precio_noche_base'    => $habitacion->getPrecioNocheBase(),
                    ':descripcion'          => $habitacion->getDescripcion()
                ];
            } else {
                $sql = "UPDATE Habitaciones
                        SET numero = :numero, piso = :piso,
                            id_tipo_habitacion = :id_tipo_habitacion,
                            id_estado_habitacion = :id_estado_habitacion,
                            precio_noche_base = :precio_noche_base
                        WHERE id = :id";
                $params = [
                    ':id'                   => $habitacion->getId(),
                    ':numero'               => $habitacion->getNumero(),
                    ':piso'                 => $habitacion->getPiso(),
                    ':id_tipo_habitacion'   => $habitacion->getIdTipoHabitacion(),
                    ':id_estado_habitacion' => $habitacion->getIdEstadoHabitacion(),
                    ':precio_noche_base'    => $habitacion->getPrecioNocheBase()
                ];
            }

            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error en Habitacion::update: " . $e->getMessage());
            throw new Exception("Error interno al actualizar la habitación.");
        }
    }

    public function deactivate(int $id): bool
    {
        try {
            $sql = "UPDATE Habitaciones
                    SET id_estado_habitacion = :nuevo_estado
                    WHERE id = :id AND id_estado_habitacion = :actual";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':nuevo_estado' => self::ESTADO_BLOQUEADA,
                ':id'           => $id,
                ':actual'       => self::ESTADO_DISPONIBLE
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::deactivate: " . $e->getMessage());
            throw new Exception("Error interno en la base de datos.");
        }
    }

    public function activate(int $id): bool
    {
        try {
            $sql = "UPDATE Habitaciones
                    SET id_estado_habitacion = :nuevo_estado
                    WHERE id = :id AND id_estado_habitacion = :actual";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':nuevo_estado' => self::ESTADO_DISPONIBLE,
                ':id'           => $id,
                ':actual'       => self::ESTADO_BLOQUEADA
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::activate: " . $e->getMessage());
            throw new Exception("Error interno en la base de datos.");
        }
    }

    public function cambiarEstado(int $id, int $nuevoEstado): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE Habitaciones SET id_estado_habitacion = :nuevo WHERE id = :id"
            );
            $stmt->execute([':nuevo' => $nuevoEstado, ':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::cambiarEstado: " . $e->getMessage());
            throw new Exception("Error interno al actualizar el estado de la habitación.");
        }
    }

    /**
     * Lee el estado actual de una habitación dentro de la conexión/transacción recibida.
     */
    public static function estadoEn(\PDO $db, int $id): int
    {
        $stmt = $db->prepare("SELECT id_estado_habitacion FROM Habitaciones WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Cambia el estado de una habitación dentro de la conexión/transacción recibida,
     * opcionalmente solo si el estado actual coincide con $soloSiEstaEn.
     */
    public static function cambiarEstadoEn(\PDO $db, int $id, int $nuevoEstado, ?int $soloSiEstaEn = null): bool
    {
        $sql = "UPDATE Habitaciones SET id_estado_habitacion = :nuevo WHERE id = :id";
        $params = [':nuevo' => $nuevoEstado, ':id' => $id];

        if ($soloSiEstaEn !== null) {
            $sql .= " AND id_estado_habitacion = :actual";
            $params[':actual'] = $soloSiEstaEn;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function countByEstado(int $idEstado): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM Habitaciones WHERE id_estado_habitacion = :estado"
            );
            $stmt->execute([':estado' => $idEstado]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Habitacion::countByEstado: " . $e->getMessage());
            throw new Exception("Error al consultar las habitaciones por estado.");
        }
    }

    /**
     * Capacidades que existen hoy en Tipos_Habitacion, de menor a mayor.
     *
     * El panel las usa para armar los filtros y los contadores. Si la lista
     * viviera en el PHP, agregar un tipo de 5 personas obligaria a tocar el
     * controller y las dos vistas: con esto, alcanza con dar de alta el tipo.
     */
    public function capacidadesExistentes(): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT DISTINCT th.capacidad
                 FROM Tipos_Habitacion th
                 JOIN Habitaciones h ON h.id_tipo_habitacion = th.id
                 WHERE th.capacidad > 0
                 ORDER BY th.capacidad ASC"
            );
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            error_log("Error en Habitacion::capacidadesExistentes: " . $e->getMessage());
            throw new Exception("Error al consultar las capacidades disponibles.");
        }
    }

    public function countDisponiblesPorCapacidad(int $capacidad): int
    {
        $tiposIds = $this->tiposPorCapacidad($capacidad);
        if ($tiposIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($tiposIds), '?'));

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM Habitaciones
                 WHERE id_estado_habitacion = ? AND id_tipo_habitacion IN ($placeholders)"
            );
            $stmt->execute(array_merge([self::ESTADO_DISPONIBLE], $tiposIds));
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Habitacion::countDisponiblesPorCapacidad: " . $e->getMessage());
            throw new Exception("Error al calcular la disponibilidad por capacidad.");
        }
    }

    public function getHabitacionesPorCapacidad(int $capacidad): array
    {
        $tiposIds = $this->tiposPorCapacidad($capacidad);
        if ($tiposIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($tiposIds), '?'));

        $sql = "SELECT h.numero, h.piso, th.descripcion AS tipo, eh.descripcion AS estado
                FROM Habitaciones h
                JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                JOIN Estados_Habitacion eh ON h.id_estado_habitacion = eh.id
                WHERE h.id_tipo_habitacion IN ($placeholders)
                ORDER BY h.numero ASC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($tiposIds);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Habitacion::getHabitacionesPorCapacidad: " . $e->getMessage());
            throw new Exception("Error al obtener las habitaciones por capacidad.");
        }
    }

    /**
     * Ids de los tipos de habitacion con la capacidad pedida.
     *
     * Devuelve un array vacio si ningun tipo tiene esa capacidad. Antes el
     * codigo caia a los tipos de 2 personas cuando la capacidad no existia, lo
     * que hacia que un filtro de "5 personas" mostrara habitaciones dobles.
     *
     * @return int[]
     */
    private function tiposPorCapacidad(int $capacidad): array
    {
        if (isset(self::$cacheTiposPorCapacidad[$capacidad])) {
            return self::$cacheTiposPorCapacidad[$capacidad];
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM Tipos_Habitacion WHERE capacidad = ? AND is_active = 1 ORDER BY id"
            );
            $stmt->execute([$capacidad]);

            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            error_log("Error en Habitacion::tiposPorCapacidad: " . $e->getMessage());
            throw new Exception("Error al consultar los tipos de habitación por capacidad.");
        }

        self::$cacheTiposPorCapacidad[$capacidad] = $ids;

        return $ids;
    }

    /**
     * Capacidad de personas de un tipo de habitacion, leida de la base.
     *
     * @throws Exception si el tipo no existe. Antes devolvia 2 por defecto, y
     * ese 2 silencioso se usaba para validar la cantidad de huespedes: un tipo
     * mal configurado dejaba pasar sobreventa sin avisar.
     */
    public static function capacidadParaTipo(int $idTipoHabitacion): int
    {
        if (isset(self::$cacheCapacidadPorTipo[$idTipoHabitacion])) {
            return self::$cacheCapacidadPorTipo[$idTipoHabitacion];
        }

        try {
            $db = (new Database())->getConnection();
            $stmt = $db->prepare("SELECT capacidad FROM Tipos_Habitacion WHERE id = ?");
            $stmt->execute([$idTipoHabitacion]);
            $capacidad = $stmt->fetchColumn();

            if ($capacidad === false) {
                throw new Exception(
                    "El tipo de habitación #{$idTipoHabitacion} no existe; "
                    . "no se puede determinar su capacidad."
                );
            }
        } catch (PDOException $e) {
            error_log("Error en Habitacion::capacidadParaTipo: " . $e->getMessage());
            throw new Exception("Error al consultar la capacidad del tipo de habitación.");
        }

        self::$cacheCapacidadPorTipo[$idTipoHabitacion] = (int)$capacidad;

        return self::$cacheCapacidadPorTipo[$idTipoHabitacion];
    }

    /**
     * Descuento por ocupacion para un tipo de habitacion.
     *
     * El descuento sigue siendo una regla de negocio del codigo; lo que se movio
     * a la base fue de que capacidad corresponde a cada tipo.
     */
    public static function descuentoParaTipo(int $idTipoHabitacion, int $cantidadHuespedes): int
    {
        $capacidad = self::capacidadParaTipo($idTipoHabitacion);
        return self::DESCUENTOS_POR_CAPACIDAD[$capacidad][$cantidadHuespedes] ?? 0;
    }

    public static function aplicarDescuento(float $precioBase, int $descuento): float
    {
        return round($precioBase - ($precioBase * $descuento / 100), 2);
    }

    public static function precioNocheParaTipo(int $idTipoHabitacion, float $precioBase, int $cantidadHuespedes): float
    {
        return self::aplicarDescuento($precioBase, self::descuentoParaTipo($idTipoHabitacion, $cantidadHuespedes));
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

    public function getNumero(): int
    {
        return $this->numero;
    }
    public function setNumero(int $numero): void
    {
        if ($numero <= 0) {
            throw new Exception("El número de habitación debe ser mayor a 0.");
        }
        $this->numero = $numero;
    }

    public function getPiso(): int
    {
        return $this->piso;
    }
    public function setPiso(int $piso): void
    {
        if ($piso < 0) {
            throw new Exception("El piso no puede ser negativo.");
        }
        $this->piso = $piso;
    }

    public function getIdTipoHabitacion(): int
    {
        return $this->id_tipo_habitacion;
    }
    public function setIdTipoHabitacion(int $id_tipo_habitacion): void
    {
        if ($id_tipo_habitacion <= 0) {
            throw new Exception("El tipo de habitación no es válido.");
        }
        $this->id_tipo_habitacion = $id_tipo_habitacion;
    }

    public function getIdEstadoHabitacion(): int
    {
        return $this->id_estado_habitacion;
    }
    public function setIdEstadoHabitacion(int $id_estado_habitacion): void
    {
        if ($id_estado_habitacion <= 0) {
            throw new Exception("El estado de habitación no es válido.");
        }
        $this->id_estado_habitacion = $id_estado_habitacion;
    }

    public function getPrecioNocheBase(): float
    {
        return $this->precio_noche_base;
    }
    public function setPrecioNocheBase(float $precio_noche_base): void
    {
        if ($precio_noche_base <= 0) {
            throw new Exception("El precio por noche debe ser mayor a 0.");
        }
        $this->precio_noche_base = $precio_noche_base;
    }

    public function getDescripcion(): string
    {
        return $this->descripcion;
    }
    public function setDescripcion(string $descripcion): void
    {
        $this->descripcion = trim($descripcion);
    }

    /**
     * Verifica si un número de habitación es consecutivo con las existentes en el mismo piso.
     * 
     * @param int $numero Número de habitación a verificar
     * @param int $piso Piso de la habitación
     * @param int|null $id ID de habitación a excluir (para ediciones)
     * @return array ['es_consecutivo' => bool, 'sugerencia' => int|null, 'mensaje' => string]
     */
    public function validarConsecutividad(int $numero, int $piso, ?int $id = null): array
    {
        try {
            $sql = "SELECT numero FROM Habitaciones WHERE piso = :piso";
            $params = [':piso' => $piso];
            
            if ($id !== null) {
                $sql .= " AND id != :id";
                $params[':id'] = $id;
            }
            
            $sql .= " ORDER BY numero ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $numeros = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            
            if (empty($numeros)) {
                return [
                    'es_consecutivo' => true,
                    'sugerencia' => 1,
                    'mensaje' => 'Es la primera habitación de este piso.'
                ];
            }
            
            $min = min($numeros);
            $max = max($numeros);
            
            // Verificar si ya existe
            if (in_array($numero, $numeros)) {
                return [
                    'es_consecutivo' => false,
                    'sugerencia' => $max + 1,
                    'mensaje' => "El número {$numero} ya existe en el piso {$piso}."
                ];
            }
            
            // Verificar consecutividad
            if ($numero === $min - 1 || $numero === $max + 1) {
                return [
                    'es_consecutivo' => true,
                    'sugerencia' => null,
                    'mensaje' => "El número {$numero} es consecutivo con las habitaciones existentes."
                ];
            }
            
            // No es consecutivo
            $sugerencia = $max + 1;
            return [
                'es_consecutivo' => false,
                'sugerencia' => $sugerencia,
                'mensaje' => "El número {$numero} no es consecutivo. Se sugiere usar {$sugerencia}."
            ];
            
        } catch (PDOException $e) {
            error_log("Error en Habitacion::validarConsecutividad: " . $e->getMessage());
            throw new Exception("Error al validar la consecutividad de habitaciones.");
        }
    }

    /**
     * Obtiene el siguiente número de habitación sugerido para un piso.
     */
    public function siguienteNumeroSugerido(int $piso): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(numero) FROM Habitaciones WHERE piso = :piso"
            );
            $stmt->execute([':piso' => $piso]);
            $max = $stmt->fetchColumn();
            
            return $max === null ? 1 : (int)$max + 1;
        } catch (PDOException $e) {
            error_log("Error en Habitacion::siguienteNumeroSugerido: " . $e->getMessage());
            return 1;
        }
    }
}
