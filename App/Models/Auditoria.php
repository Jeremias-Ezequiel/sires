<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;

/**
 * Bitacora de acciones con plata o con huesped.
 *
 * No se registra TODO lo que pasa: solo lo que despues alguien va a necesitar
 * defendible ("este cobro lo hizo fulanito desde esta IP, y el saldo paso de
 * esto a aquello"). Meter cada UPDATE daria millones de filas sin responder
 * ninguna pregunta util.
 *
 * Regla de oro: registrar jamas puede romper la operacion de negocio. Si el
 * insert falla, se avisa por error_log y se sigue. Un hotel no puede quedarse
 * sin check-in porque la bitacora este caída.
 */
class Auditoria extends Model
{
    public const LOGIN    = 'LOGIN';
    public const CHECKIN  = 'CHECKIN';
    public const CHECKOUT = 'CHECKOUT';
    public const PAGO     = 'PAGO';
    public const ANULAR   = 'ANULAR';

    /** Meses que se conservan antes de purgar. Configurable desde el .env. */
    public const MESES_POR_DEFECTO = 24;

    /** Cuanto cabe en un TEXT, con margen para no pegarle al limite de la fila. */
    private const MAX_JSON = 60000;

    /**
     * Campos que jamas se copian a la bitacora. Guardar una clave o un token en
     * auditoria seria tirar el secreto a una tabla que se lee, exporta y
     * respalda mucho mas que la de usuarios.
     */
    private const CAMPOS_SENSIBLES = [
        'password', 'password_hash', 'clave', 'clave_hash',
        'token', 'reset_token', 'reset_expires_at', 'secret',
    ];

    /**
     * Registra un hecho. Devuelve si quedo anotado, para poder avisar en tests.
     *
     * @param array<string,mixed>|null $datosAntes   estado previo, para el diff
     * @param array<string,mixed>|null $datosDespues estado posterior
     */
    public function registrar(
        string $accion,
        string $entidad,
        ?int $entidadId = null,
        ?string $descripcion = null,
        ?array $datosAntes = null,
        ?array $datosDespues = null,
        ?int $idUsuario = null
    ): bool {
        try {
            $usuario = $this->resolverUsuario($idUsuario);

            $sql = "INSERT INTO auditoria
                    (id_usuario, usuario_nombre, accion, entidad, entidad_id,
                     descripcion, datos_antes, datos_despues, ip, user_agent)
                    VALUES
                    (:id_usuario, :usuario_nombre, :accion, :entidad, :entidad_id,
                     :descripcion, :datos_antes, :datos_despues, :ip, :ua)";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id_usuario', $usuario['id'], $usuario['id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':usuario_nombre', $usuario['nombre']);
            $stmt->bindValue(':accion', mb_substr($accion, 0, 50));
            $stmt->bindValue(':entidad', mb_substr($entidad, 0, 50));
            $stmt->bindValue(':entidad_id', $entidadId, $entidadId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':descripcion', $descripcion === null ? null : mb_substr($descripcion, 0, 255));
            $stmt->bindValue(':datos_antes', $this->serializar($datosAntes));
            $stmt->bindValue(':datos_despues', $this->serializar($datosDespues));
            $stmt->bindValue(':ip', client_ip());
            $stmt->bindValue(':ua', client_user_agent());

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Auditoria::registrar fallo (" . $accion . "): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Quien hizo el hecho.
     *
     * usuario_nombre va como copia del nombre en el momento, no como un JOIN a
     * usuarios: si despues dan de baja o borran al empleado, la FK pasa a NULL
     * pero el nombre queda en el rasto. Con un JOIN, el rasto se quedaria en
     * blanco justo cuando mas falta hace saber quien era.
     *
     * La id se valida contra la base antes de mandarla. Si la sesion quedara
     * con un id de usuario que ya no existe, la FK rechazaria el INSERT entero
     * y se perderia la anotacion.
     */
    private function resolverUsuario(?int $idUsuario): array
    {
        $id = $idUsuario ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
        $nombre = $_SESSION['user_name'] ?? null;

        if ($id === null || $id <= 0) {
            return ['id' => null, 'nombre' => $nombre === null ? null : mb_substr((string) $nombre, 0, 100)];
        }

        try {
            $stmt = $this->db->prepare("SELECT nombre, apellido FROM usuarios WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($fila === false) {
                return ['id' => null, 'nombre' => null];
            }

            $completo = trim($fila['nombre'] . ' ' . $fila['apellido']);

            return [
                'id' => $id,
                'nombre' => mb_substr($completo !== '' ? $completo : (string) $nombre, 0, 100),
            ];
        } catch (PDOException $e) {
            // Si no se puede leer el nombre, se sigue con el de la sesion: peor
            // un nombre viejo que ningun registro del hecho.
            error_log("Auditoria: no se pudo resolver el usuario $id: " . $e->getMessage());
            return ['id' => null, 'nombre' => $nombre === null ? null : mb_substr((string) $nombre, 0, 100)];
        }
    }

    /**
     * Convierte un array a JSON limpio para guardar.
     *
     * - Filtra los campos sensibles.
     * - Convierte a string lo que no sea escalar, porque un DateTime o un
     *   resource romperian el json_encode y perderiamos toda la fila.
     * - JSON_PARTIAL_OUTPUT_ON_ERROR evita que un valor raro tire el registro
     *   entero abajo.
     */
    private function serializar(?array $datos): ?string
    {
        if ($datos === null || $datos === []) {
            return null;
        }

        $limpio = $this->filtrarSensibles($datos);

        $json = json_encode($limpio, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            return null;
        }

        return mb_strlen($json) > self::MAX_JSON
            ? mb_substr($json, 0, self::MAX_JSON)
            : $json;
    }

    /**
     * Saca las claves sensibles en cualquier nivel del array.
     */
    private function filtrarSensibles(array $datos): array
    {
        $limpio = [];

        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && in_array(mb_strtolower($clave), self::CAMPOS_SENSIBLES, true)) {
                continue;
            }

            $limpio[$clave] = is_array($valor) ? $this->filtrarSensibles($valor) : $this->aTexto($valor);
        }

        return $limpio;
    }

    /**
     * Deja todo en algo que json_encode pueda manejar. Los objetos con
     * __toString se aprovechan; el resto se describe en vez de romper.
     */
    private function aTexto(mixed $valor): mixed
    {
        if (is_scalar($valor) || $valor === null) {
            return $valor;
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        if (is_object($valor) && method_exists($valor, '__toString')) {
            return (string) $valor;
        }

        if (is_object($valor)) {
            return get_class($valor);
        }

        if (is_resource($valor)) {
            return 'recurso';
        }

        return null;
    }

    /**
     * Historial para una entidad concreta. Sirve para la pantalla de detalle de
     * una reserva o de un cobro: "todo lo que le pasaron a esto".
     */
    public function historial(string $entidad, int $entidadId, int $limite = 20): array
    {
        try {
            $sql = "SELECT id, id_usuario, usuario_nombre, accion, descripcion,
                           datos_antes, datos_despues, ip, user_agent, fecha
                      FROM auditoria
                     WHERE entidad = :entidad AND entidad_id = :id
                     ORDER BY id DESC
                     LIMIT :limite";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':entidad', $entidad, PDO::PARAM_STR);
            $stmt->bindValue(':id', $entidadId, PDO::PARAM_INT);
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Auditoria::historial fallo: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Los ultimos movimientos del sistema, para una pantalla general.
     */
    public function recientes(int $limite = 50, ?string $accion = null): array
    {
        try {
            $sql = "SELECT id, id_usuario, usuario_nombre, accion, entidad,
                           entidad_id, descripcion, ip, fecha
                      FROM auditoria";

            if ($accion !== null) {
                $sql .= " WHERE accion = :accion";
            }

            $sql .= " ORDER BY id DESC LIMIT :limite";

            $stmt = $this->db->prepare($sql);
            if ($accion !== null) {
                $stmt->bindValue(':accion', $accion, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Auditoria::recientes fallo: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Borra los registros viejos. Sin esto la tabla crece para siempre: esta
     * tiene menos trafico que Login_Intentos, pero tambien es la que se
     * consulta para responder reclamos viejos, y a los 3 años ya no le
     * interesa a nadie. Llamar desde un cron.
     */
    public function purgarAntiguos(?int $meses = null): int
    {
        try {
            if ($meses === null) {
                $meses = (int)($_ENV['AUDITORIA_MESES'] ?? self::MESES_POR_DEFECTO);
            }

            $stmt = $this->db->prepare("DELETE FROM auditoria
                                        WHERE fecha < DATE_SUB(NOW(), INTERVAL :mes MONTH)");
            $stmt->bindValue(':mes', $meses, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Auditoria::purgarAntiguos fallo: " . $e->getMessage());
            return 0;
        }
    }
}
