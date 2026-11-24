<?php

declare(strict_types=1);

namespace Tests\Integracion;

use App\Config\Database;
use App\Models\Habitacion;
use App\Models\Reserva;
use App\Models\Rol;
use App\Services\PagoService;
use App\Services\ReservaService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base de los tests de integración. Se corren dentro de la VM, con el .env que ya
 * tiene el proyecto ahí (no se crea uno nuevo):
 *
 *     vendor/bin/phpunit --testsuite integracion
 *
 * Cada test corre dentro de una transacción que termina con ROLLBACK, así no deja datos.
 * Sin DB_HOST o sin pdo_mysql (por ejemplo, en la máquina de desarrollo) se saltean.
 */
abstract class TestIntegracion extends TestCase
{
    private static ?PdoConSavepoints $conexion = null;

    protected PdoConSavepoints $db;

    protected function setUp(): void
    {
        $this->db = self::conexion();
        $this->db->iniciarTest();
        $_SESSION = ['user_id' => $this->idUsuario(Rol::ADMINISTRADOR)];
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->deshacerTodo();
        }
        $_SESSION = [];
    }

    private static function conexion(): PdoConSavepoints
    {
        if (self::$conexion !== null) {
            return self::$conexion;
        }
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('Falta la extensión pdo_mysql: correr dentro de la VM.');
        }
        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        if (empty($_ENV['DB_HOST']) || empty($_ENV['DB_NAME'])) {
            self::markTestSkipped('Sin DB_HOST/DB_NAME en el .env del proyecto: correr dentro de la VM.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'] ?? '3306',
            $_ENV['DB_NAME']
        );
        $pdo = new PdoConSavepoints($dsn, (string)($_ENV['DB_USER'] ?? ''), (string)($_ENV['DB_PASS'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // La app usa esta conexión (Database es un singleton): todo pasa por la transacción del test.
        (new \ReflectionProperty(Database::class, 'instance'))->setValue(null, $pdo);

        return self::$conexion = $pdo;
    }

    // ------------------------------------------------------------------
    // Datos de prueba (se crean dentro de la transacción del test)
    // ------------------------------------------------------------------

    protected function valor(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    protected function fila(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($fila, 'No se encontró la fila: ' . $sql);
        return $fila;
    }

    protected function idUsuario(int $rol): int
    {
        $id = $this->valor('SELECT id FROM Usuarios WHERE id_rol = :rol AND is_active = 1 ORDER BY id LIMIT 1', [':rol' => $rol]);
        if ($id === false) {
            $this->markTestSkipped('No hay un usuario activo con el rol ' . $rol . '.');
        }
        return (int)$id;
    }

    protected function crearCliente(): int
    {
        $sufijo = bin2hex(random_bytes(5));
        $stmt = $this->db->prepare(
            "INSERT INTO Clientes (id_nacionalidad, id_provincia, nombre, apellido, dni_pasaporte, email)
             VALUES ((SELECT MIN(id) FROM Nacionalidades), (SELECT MIN(id) FROM Provincias),
                     'Test', 'Integracion', :dni, :email)"
        );
        $stmt->execute([':dni' => 'IT-' . $sufijo, ':email' => 'it-' . $sufijo . '@prueba.com']);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Habitación activa, reservable y sin reservas que se solapen con el rango.
     *
     * @return array{id: int, precio_noche_base: float, id_tipo_habitacion: int}
     */
    protected function habitacionLibre(string $entrada, string $salida): array
    {
        $stmt = $this->db->prepare(
            "SELECT h.id, h.precio_noche_base, h.id_tipo_habitacion
             FROM Habitaciones h
             WHERE h.is_active = 1
               AND h.id_estado_habitacion NOT IN (:mant, :bloq)
               AND NOT EXISTS (
                   SELECT 1 FROM Reservas r
                   WHERE r.id_habitacion = h.id
                     AND r.fecha_entrada < :salida AND r.fecha_salida > :entrada
                     AND r.id_estado_reserva IN (:pend, :conf, :encasa, :nsp)
               )
             ORDER BY h.id LIMIT 1"
        );
        $stmt->execute([
            ':mant' => Habitacion::ESTADO_MANTENIMIENTO, ':bloq' => Habitacion::ESTADO_BLOQUEADA,
            ':entrada' => $entrada, ':salida' => $salida,
            ':pend' => Reserva::ESTADO_PENDIENTE, ':conf' => Reserva::ESTADO_CONFIRMADA,
            ':encasa' => Reserva::ESTADO_EN_CASA, ':nsp' => Reserva::ESTADO_NO_SHOW_CON_PAGO,
        ]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            $this->markTestSkipped("No hay habitaciones libres del {$entrada} al {$salida}.");
        }
        return [
            'id' => (int)$fila['id'],
            'precio_noche_base' => (float)$fila['precio_noche_base'],
            'id_tipo_habitacion' => (int)$fila['id_tipo_habitacion'],
        ];
    }

    protected function metodoPago(): int
    {
        return (int)$this->valor('SELECT MIN(id) FROM Metodos_Pago WHERE is_active = 1');
    }

    /** Crea una reserva con ReservaService y devuelve su id. */
    protected function crearReserva(string $entrada, string $salida, int $huespedes = 1): int
    {
        $habitacion = $this->habitacionLibre($entrada, $salida);
        $idCliente = $this->crearCliente();
        (new ReservaService())->crear([
            'id_cliente'         => $idCliente,
            'id_habitacion'      => $habitacion['id'],
            'id_canal_origen'    => (int)$this->valor('SELECT MIN(id) FROM Canal_Origen'),
            'fecha_entrada'      => $entrada,
            'fecha_salida'       => $salida,
            'cantidad_huespedes' => $huespedes,
            'creado_por'         => $this->idUsuario(Rol::RECEPCIONISTA),
        ]);
        return (int)$this->valor('SELECT MAX(id) FROM Reservas WHERE id_cliente = :c', [':c' => $idCliente]);
    }

    protected function pagar(int $idReserva, float $monto): void
    {
        (new PagoService())->registrarPago($idReserva, $this->metodoPago(), $monto, (int)$_SESSION['user_id']);
    }

    /** Fecha Y-m-d a $dias de hoy. */
    protected static function dentroDe(int $dias): string
    {
        return date('Y-m-d', strtotime('+' . $dias . ' day'));
    }
}
