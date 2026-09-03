<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Reserva extends Model
{
    private int $id;
    private int $id_cliente;
    private int $id_habitacion;
    private int $id_estado_reserva;
    private int $id_canal_origen;
    private string $fecha_entrada;
    private string $fecha_salida;
    private int $cantidad_huespedes;
    private ?string $observaciones = null;
    private int $creado_por;
    private int $is_active = 1;
    private string $fecha_alta;
    private ?string $fecha_baja = null;

    public const ESTADO_PENDIENTE = 1;
    public const ESTADO_CONFIRMADA = 2;
    public const ESTADO_CANCELADA = 3;
    public const ESTADO_FINALIZADA = 4;

    public function countReservasHoy(?string $fecha = null): int
    {
        $fecha = $fecha ?? date('Y-m-d');

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM Reservas
                 WHERE fecha_entrada = :fecha AND id_estado_reserva <> :cancelada"
            );
            $stmt->execute([
                ':fecha'     => $fecha,
                ':cancelada' => self::ESTADO_CANCELADA
            ]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Reserva::countReservasHoy: " . $e->getMessage());
            throw new Exception("Error al consultar las reservas del día.");
        }
    }

    public function getAllWithFilters(
        ?string $search,
        ?string $estado,
        ?string $canal,
        int $limit,
        int $offset
    ): array {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $conditions[] = "(c.nombre LIKE :search OR c.apellido LIKE :search2 OR h.numero LIKE :search3)";
            $params['search'] = "%" . $search . "%";
            $params['search2'] = "%" . $search . "%";
            $params['search3'] = "%" . $search . "%";
        }

        if ($estado !== null && $estado !== '') {
            $conditions[] = "r.id_estado_reserva = :estado";
            $params['estado'] = (int)$estado;
        }

        if ($canal !== null && $canal !== '') {
            $conditions[] = "r.id_canal_origen = :canal";
            $params['canal'] = (int)$canal;
        }

        $sql = "SELECT r.id, r.fecha_entrada, r.fecha_salida, r.cantidad_huespedes,
                       r.observaciones, r.fecha_alta,
                       c.nombre AS cliente_nombre, c.apellido AS cliente_apellido,
                       h.numero AS habitacion_numero,
                       er.descripcion AS estado_descripcion,
                       co.descripcion AS canal_descripcion,
                       r.id_estado_reserva, r.id_habitacion
                FROM Reservas r
                JOIN Clientes c ON r.id_cliente = c.id
                JOIN Habitaciones h ON r.id_habitacion = h.id
                JOIN Estados_Reserva er ON r.id_estado_reserva = er.id
                JOIN Canal_Origen co ON r.id_canal_origen = co.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY r.fecha_alta DESC LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Reserva::getAllWithFilters: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar reservas.");
        }
    }

    public function countAllWithFilters(?string $search, ?string $estado, ?string $canal): int
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $conditions[] = "(c.nombre LIKE :search OR c.apellido LIKE :search2 OR h.numero LIKE :search3)";
            $params['search'] = "%" . $search . "%";
            $params['search2'] = "%" . $search . "%";
            $params['search3'] = "%" . $search . "%";
        }

        if ($estado !== null && $estado !== '') {
            $conditions[] = "r.id_estado_reserva = :estado";
            $params['estado'] = (int)$estado;
        }

        if ($canal !== null && $canal !== '') {
            $conditions[] = "r.id_canal_origen = :canal";
            $params['canal'] = (int)$canal;
        }

        $sql = "SELECT COUNT(*)
                FROM Reservas r
                JOIN Clientes c ON r.id_cliente = c.id
                JOIN Habitaciones h ON r.id_habitacion = h.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en Reserva::countAllWithFilters: " . $e->getMessage());
            throw new Exception("Error en la base de datos al contar reservas.");
        }
    }

    public function getEstadosReserva(): array
    {
        try {
            $stmt = $this->db->query("SELECT id, descripcion FROM Estados_Reserva ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Reserva::getEstadosReserva: " . $e->getMessage());
            return [];
        }
    }

    public function getCanalesOrigen(): array
    {
        try {
            $stmt = $this->db->query("SELECT id, descripcion FROM Canal_Origen ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Reserva::getCanalesOrigen: " . $e->getMessage());
            return [];
        }
    }

    public function findById(int $id): ?array
    {
        try {
            $sql = "SELECT r.*,
                           c.nombre AS cliente_nombre, c.apellido AS cliente_apellido,
                           c.dni_pasaporte AS cliente_dni, c.mail AS cliente_email, c.telefono AS cliente_telefono,
                           h.numero AS habitacion_numero, h.piso AS habitacion_piso, h.precio_noche_base,
                           th.descripcion AS tipo_habitacion_descripcion,
                           er.descripcion AS estado_descripcion,
                           co.descripcion AS canal_descripcion,
                           rp.id_estado_pago, rp.monto_total, rp.monto_cobrado, rp.saldo_pendiente,
                           ep.descripcion AS estado_pago_descripcion
                    FROM Reservas r
                    JOIN Clientes c ON r.id_cliente = c.id
                    JOIN Habitaciones h ON r.id_habitacion = h.id
                    JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                    JOIN Estados_Reserva er ON r.id_estado_reserva = er.id
                    JOIN Canal_Origen co ON r.id_canal_origen = co.id
                    LEFT JOIN Resumen_Pago rp ON rp.id_reserva = r.id
                    LEFT JOIN Estados_Pago ep ON rp.id_estado_pago = ep.id
                    WHERE r.id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Error en Reserva::findById: " . $e->getMessage());
            throw new Exception("Error interno al buscar la reserva.");
        }
    }

    public function save(Reserva $reserva): bool
    {
        try {
            if ($reserva->getFechaSalida() <= $reserva->getFechaEntrada()) {
                throw new Exception("La fecha de salida debe ser posterior a la fecha de entrada.");
            }

            $sql = "INSERT INTO Reservas (id_cliente, id_habitacion, id_estado_reserva, id_canal_origen, fecha_entrada, fecha_salida, cantidad_huespedes, observaciones, creado_por, fecha_alta)
                    VALUES (:id_cliente, :id_habitacion, :id_estado_reserva, :id_canal_origen, :fecha_entrada, :fecha_salida, :cantidad_huespedes, :observaciones, :creado_por, NOW())";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id_cliente'         => $reserva->getIdCliente(),
                ':id_habitacion'      => $reserva->getIdHabitacion(),
                ':id_estado_reserva'  => self::ESTADO_PENDIENTE,
                ':id_canal_origen'    => $reserva->getIdCanalOrigen(),
                ':fecha_entrada'      => $reserva->getFechaEntrada(),
                ':fecha_salida'       => $reserva->getFechaSalida(),
                ':cantidad_huespedes' => $reserva->getCantidadHuespedes(),
                ':observaciones'      => $reserva->getObservaciones(),
                ':creado_por'         => $reserva->getCreadoPor()
            ]);
        } catch (PDOException $e) {
            error_log("Error en Reserva::save: " . $e->getMessage());
            throw new Exception("Error interno al registrar la reserva.");
        }
    }

    public function update(Reserva $reserva): bool
    {
        try {
            if ($reserva->getFechaSalida() <= $reserva->getFechaEntrada()) {
                throw new Exception("La fecha de salida debe ser posterior a la fecha de entrada.");
            }

            $sql = "UPDATE Reservas
                    SET id_cliente = :id_cliente,
                        id_habitacion = :id_habitacion,
                        id_canal_origen = :id_canal_origen,
                        fecha_entrada = :fecha_entrada,
                        fecha_salida = :fecha_salida,
                        cantidad_huespedes = :cantidad_huespedes,
                        observaciones = :observaciones
                    WHERE id = :id AND id_estado_reserva IN (:pendiente, :confirmada)";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id'                => $reserva->getId(),
                ':id_cliente'        => $reserva->getIdCliente(),
                ':id_habitacion'     => $reserva->getIdHabitacion(),
                ':id_canal_origen'   => $reserva->getIdCanalOrigen(),
                ':fecha_entrada'     => $reserva->getFechaEntrada(),
                ':fecha_salida'      => $reserva->getFechaSalida(),
                ':cantidad_huespedes'=> $reserva->getCantidadHuespedes(),
                ':observaciones'     => $reserva->getObservaciones(),
                ':pendiente'         => self::ESTADO_PENDIENTE,
                ':confirmada'        => self::ESTADO_CONFIRMADA
            ]);
        } catch (PDOException $e) {
            error_log("Error en Reserva::update: " . $e->getMessage());
            throw new Exception("Error interno al actualizar la reserva.");
        }
    }

    public function cambiarEstado(int $id, int $nuevoEstado, ?int $soloSiEstaEn = null): bool
    {
        try {
            $sql = "UPDATE Reservas SET id_estado_reserva = :nuevo WHERE id = :id";
            $params = [':nuevo' => $nuevoEstado, ':id' => $id];

            if ($soloSiEstaEn !== null) {
                $sql .= " AND id_estado_reserva = :actual";
                $params[':actual'] = $soloSiEstaEn;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error en Reserva::cambiarEstado: " . $e->getMessage());
            throw new Exception("Error interno al cambiar el estado de la reserva.");
        }
    }

    public function getId(): int
    {
        return $this->id;
    }
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getIdCliente(): int
    {
        return $this->id_cliente;
    }
    public function setIdCliente(int $id_cliente): void
    {
        if ($id_cliente <= 0) {
            throw new Exception("El cliente no es válido.");
        }
        $this->id_cliente = $id_cliente;
    }

    public function getIdHabitacion(): int
    {
        return $this->id_habitacion;
    }
    public function setIdHabitacion(int $id_habitacion): void
    {
        if ($id_habitacion <= 0) {
            throw new Exception("La habitación no es válida.");
        }
        $this->id_habitacion = $id_habitacion;
    }

    public function getIdEstadoReserva(): int
    {
        return $this->id_estado_reserva;
    }
    public function setIdEstadoReserva(int $id_estado_reserva): void
    {
        if ($id_estado_reserva <= 0) {
            throw new Exception("El estado de reserva no es válido.");
        }
        $this->id_estado_reserva = $id_estado_reserva;
    }

    public function getIdCanalOrigen(): int
    {
        return $this->id_canal_origen;
    }
    public function setIdCanalOrigen(int $id_canal_origen): void
    {
        if ($id_canal_origen <= 0) {
            throw new Exception("El canal de origen no es válido.");
        }
        $this->id_canal_origen = $id_canal_origen;
    }

    public function getFechaEntrada(): string
    {
        return $this->fecha_entrada;
    }
    public function setFechaEntrada(string $fecha_entrada): void
    {
        if (empty($fecha_entrada)) {
            throw new Exception("La fecha de entrada es obligatoria.");
        }
        $this->fecha_entrada = $fecha_entrada;
    }

    public function getFechaSalida(): string
    {
        return $this->fecha_salida;
    }
    public function setFechaSalida(string $fecha_salida): void
    {
        if (empty($fecha_salida)) {
            throw new Exception("La fecha de salida es obligatoria.");
        }
        $this->fecha_salida = $fecha_salida;
    }

    public function getCantidadHuespedes(): int
    {
        return $this->cantidad_huespedes;
    }
    public function setCantidadHuespedes(int $cantidad_huespedes): void
    {
        if ($cantidad_huespedes < 1) {
            throw new Exception("La cantidad de huéspedes debe ser al menos 1.");
        }
        $this->cantidad_huespedes = $cantidad_huespedes;
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

    public function getCreadoPor(): int
    {
        return $this->creado_por;
    }
    public function setCreadoPor(int $creado_por): void
    {
        if ($creado_por <= 0) {
            throw new Exception("El usuario creador no es válido.");
        }
        $this->creado_por = $creado_por;
    }

    public function getIsActive(): int
    {
        return $this->is_active;
    }
    public function setIsActive(int $is_active): void
    {
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
}
