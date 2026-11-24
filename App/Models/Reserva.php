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

    // Alias: los valores se definen en EstadoReserva (espejo de la tabla catálogo).
    /**
     * Separador SQL para sumar un mensaje a `observaciones` (mismo formato que
     * ReglasReserva::agregarObservacion): "- " en la primera línea, salto + "- " después.
     * Uso: SET observaciones = CONCAT(COALESCE(observaciones, ''), SQL_SEPARADOR_OBS, 'Mensaje').
     */
    public const SQL_SEPARADOR_OBS = "IF(COALESCE(observaciones, '') = '', '- ', CONCAT(CHAR(10), '- '))";

    public const ESTADO_PENDIENTE = EstadoReserva::PENDIENTE;

    /** Estados que ocupan noches de la habitación: control de solapamiento y agenda de disponibilidad. */
    public const ESTADOS_QUE_OCUPAN = [
        EstadoReserva::PENDIENTE,
        EstadoReserva::CONFIRMADA,
        EstadoReserva::EN_CASA,
        EstadoReserva::NO_SHOW_CON_PAGO,
    ];
    public const ESTADO_CONFIRMADA = EstadoReserva::CONFIRMADA;
    public const ESTADO_CANCELADA = EstadoReserva::CANCELADA;
    public const ESTADO_FINALIZADA = EstadoReserva::FINALIZADA_CHECKOUT;
    public const ESTADO_EN_CASA = EstadoReserva::EN_CASA;
    public const ESTADO_NO_SHOW = EstadoReserva::NO_SHOW;
    public const ESTADO_NO_SHOW_CON_PAGO = EstadoReserva::NO_SHOW_CON_PAGO;

    /** Días hacia adelante para avisar de reservas pendientes de pago (alerta del inicio). */
    public const DIAS_AVISO_SIN_PAGO = 3;

    /** Filtros de alerta del inicio que entiende el listado de reservas (?alerta=...). */
    public const ALERTAS = [
        'sin_pago_proximas'  => 'Pendientes de pago que llegan en los próximos ' . self::DIAS_AVISO_SIN_PAGO . ' días',
        'llegadas_atrasadas' => 'Confirmadas que no hicieron check-in',
        'salidas_vencidas'   => 'Huéspedes con la salida vencida sin check-out',
    ];

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

    /**
     * Reservas que ocupan noches (las mismas que bloquea existeSolapamiento) y que todavía no
     * terminaron, con el cliente: las usa la agenda de disponibilidad del alta y la edición.
     */
    public function getReservasActivas(): array
    {
        [$in, $params] = self::placeholdersEstadosQueOcupan();
        try {
            $stmt = $this->db->prepare(
                "SELECT r.id, r.id_habitacion, r.fecha_entrada, r.fecha_salida, r.id_estado_reserva,
                        c.nombre AS cliente_nombre, c.apellido AS cliente_apellido
                 FROM Reservas r
                 LEFT JOIN Clientes c ON c.id = r.id_cliente
                 WHERE r.id_estado_reserva IN ($in)
                   AND r.fecha_salida >= CURDATE()
                 ORDER BY r.fecha_entrada ASC"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error en Reserva::getReservasActivas: " . $e->getMessage());
            throw new Exception("Error al consultar las reservas activas.");
        }
    }

    /**
     * Placeholders y parámetros para "id_estado_reserva IN (...)" con ESTADOS_QUE_OCUPAN.
     * @return array{0: string, 1: array<string, int>}
     */
    private static function placeholdersEstadosQueOcupan(): array
    {
        $params = [];
        foreach (self::ESTADOS_QUE_OCUPAN as $i => $estado) {
            $params[':ocupa' . $i] = $estado;
        }
        return [implode(', ', array_keys($params)), $params];
    }

    public function existeSolapamiento(
        int $idHabitacion,
        string $fechaEntrada,
        string $fechaSalida,
        ?int $exceptoId = null
    ): bool {
        [$in, $estados] = self::placeholdersEstadosQueOcupan();
        try {
            $sql = "SELECT COUNT(*) FROM Reservas
                    WHERE id_habitacion = :id_habitacion
                      AND fecha_entrada < :fecha_salida
                      AND fecha_salida > :fecha_entrada
                      AND id_estado_reserva IN ($in)";

            $params = [
                ':id_habitacion' => $idHabitacion,
                ':fecha_entrada' => $fechaEntrada,
                ':fecha_salida'  => $fechaSalida,
            ] + $estados;

            if ($exceptoId !== null) {
                $sql .= " AND id <> :excepto_id";
                $params[':excepto_id'] = $exceptoId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Error en Reserva::existeSolapamiento: " . $e->getMessage());
            throw new Exception("Error al verificar la disponibilidad de la habitación.");
        }
    }

    public function getAllWithFilters(
        ?string $search,
        ?string $estado,
        ?string $canal,
        int $limit,
        int $offset,
        ?string $alerta = null
    ): array {
        $conditions = [];
        $params = [];
        $this->aplicarFiltroAlerta($alerta, $conditions, $params);

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

        $sql .= " ORDER BY r.fecha_alta DESC, r.id DESC LIMIT :limit OFFSET :offset";

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

    public function countAllWithFilters(?string $search, ?string $estado, ?string $canal, ?string $alerta = null): int
    {
        $conditions = [];
        $params = [];
        $this->aplicarFiltroAlerta($alerta, $conditions, $params);

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

    /**
     * Mismas condiciones que getContadoresAlerta(), para que el listado muestre
     * exactamente las reservas que cuenta cada alerta del inicio.
     */
    private function aplicarFiltroAlerta(?string $alerta, array &$conditions, array &$params): void
    {
        if ($alerta === null || !isset(self::ALERTAS[$alerta])) {
            return;
        }
        $hoy = date('Y-m-d');
        switch ($alerta) {
            case 'sin_pago_proximas':
                $conditions[] = "r.id_estado_reserva = :al_estado AND r.fecha_entrada BETWEEN :al_hoy AND :al_limite";
                $params['al_estado'] = self::ESTADO_PENDIENTE;
                $params['al_hoy'] = $hoy;
                $params['al_limite'] = date('Y-m-d', strtotime('+' . self::DIAS_AVISO_SIN_PAGO . ' days'));
                break;
            case 'llegadas_atrasadas':
                $conditions[] = "r.id_estado_reserva = :al_estado AND r.fecha_entrada < :al_hoy AND r.fecha_salida > :al_hoy2";
                $params['al_estado'] = self::ESTADO_CONFIRMADA;
                $params['al_hoy'] = $hoy;
                $params['al_hoy2'] = $hoy;
                break;
            case 'salidas_vencidas':
                $conditions[] = "r.id_estado_reserva = :al_estado AND r.fecha_salida < :al_hoy";
                $params['al_estado'] = self::ESTADO_EN_CASA;
                $params['al_hoy'] = $hoy;
                break;
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
                           c.dni_pasaporte AS cliente_dni, c.email AS cliente_email, c.telefono AS cliente_telefono,
                           h.numero AS habitacion_numero, h.piso AS habitacion_piso, h.precio_noche_base,
                           h.id_tipo_habitacion,
                           th.descripcion AS tipo_habitacion_descripcion,
                           er.descripcion AS estado_descripcion,
                           co.descripcion AS canal_descripcion,
                           rp.id_estado_pago, rp.monto_total, rp.monto_cobrado, rp.saldo_pendiente,
                           ep.descripcion AS estado_pago_descripcion
                    FROM Reservas r
                    JOIN Clientes c ON r.id_cliente = c.id
                    JOIN Habitaciones h ON r.id_habitacion = h.id
                    LEFT JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                    JOIN Estados_Reserva er ON r.id_estado_reserva = er.id
                    LEFT JOIN Canal_Origen co ON r.id_canal_origen = co.id
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

    /** Día real en que el huésped se retiró (check-out o checkout anticipado). fecha_salida sigue siendo la pactada. */
    public function registrarCheckout(int $id, string $fecha): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE Reservas SET fecha_checkout = :fecha WHERE id = :id");
            $stmt->execute([':fecha' => $fecha, ':id' => $id]);
        } catch (PDOException $e) {
            error_log("Error en Reserva::registrarCheckout: " . $e->getMessage());
            throw new Exception("Error al registrar la fecha de salida.");
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

    /**
     * Guarda el precio efectivo por noche pactado (con descuento por ocupación).
     * Lo usa el late checkout para no depender del precio actual de la habitación.
     */
    public function actualizarPrecioPactado(int $id, float $precioNoche): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE Reservas SET precio_noche_pactado = :precio WHERE id = :id"
            );
            $stmt->execute([':precio' => round($precioNoche, 2), ':id' => $id]);
        } catch (PDOException $e) {
            error_log("Error en Reserva::actualizarPrecioPactado: " . $e->getMessage());
            throw new Exception("Error interno al guardar el precio de la reserva.");
        }
    }

    // =====================================================================
    // TABLERO DE INICIO
    // =====================================================================

    /** Llegadas de una fecha: reservas vigentes que entran ese día, con huésped, habitación y pago. */
    public function getLlegadasDelDia(string $fecha): array
    {
        return $this->movimientosDelDia('r.fecha_entrada', $fecha, [
            self::ESTADO_PENDIENTE,
            self::ESTADO_CONFIRMADA,
            self::ESTADO_EN_CASA,
            self::ESTADO_NO_SHOW_CON_PAGO,
        ]);
    }

    /** Salidas de una fecha: huéspedes en casa que se van ese día y los que ya hicieron check-out. */
    public function getSalidasDelDia(string $fecha): array
    {
        return $this->movimientosDelDia('r.fecha_salida', $fecha, [
            self::ESTADO_EN_CASA,
            self::ESTADO_FINALIZADA,
        ]);
    }

    /** @param string $columna 'r.fecha_entrada' o 'r.fecha_salida' (valor fijo, nunca del usuario). */
    private function movimientosDelDia(string $columna, string $fecha, array $estados): array
    {
        $placeholders = implode(',', array_fill(0, count($estados), '?'));
        $sql = "SELECT r.id, r.id_estado_reserva, r.fecha_entrada, r.fecha_salida, r.cantidad_huespedes,
                       c.nombre AS cliente_nombre, c.apellido AS cliente_apellido,
                       h.id AS id_habitacion, h.numero AS habitacion_numero,
                       th.descripcion AS tipo_habitacion,
                       rp.id_estado_pago, rp.saldo_pendiente
                FROM Reservas r
                JOIN Clientes c ON r.id_cliente = c.id
                JOIN Habitaciones h ON r.id_habitacion = h.id
                LEFT JOIN Tipos_Habitacion th ON h.id_tipo_habitacion = th.id
                LEFT JOIN Resumen_Pago rp ON rp.id_reserva = r.id
                WHERE $columna = ? AND r.id_estado_reserva IN ($placeholders)
                ORDER BY h.numero ASC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge([$fecha], $estados));
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Reserva::movimientosDelDia: " . $e->getMessage());
            throw new Exception("Error al consultar las llegadas y salidas del día.");
        }
    }

    /**
     * Estadías que ocupan al menos una noche en [desde, hasta): pendientes, confirmadas y en casa.
     * @return array<int, array{fecha_entrada: string, fecha_salida: string}>
     */
    public function getEstadiasEnRango(string $desde, string $hasta): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT fecha_entrada, fecha_salida FROM Reservas
                 WHERE fecha_entrada < :hasta AND fecha_salida > :desde
                   AND id_estado_reserva IN (:pendiente, :confirmada, :en_casa)"
            );
            $stmt->execute([
                ':desde'      => $desde,
                ':hasta'      => $hasta,
                ':pendiente'  => self::ESTADO_PENDIENTE,
                ':confirmada' => self::ESTADO_CONFIRMADA,
                ':en_casa'    => self::ESTADO_EN_CASA,
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en Reserva::getEstadiasEnRango: " . $e->getMessage());
            throw new Exception("Error al consultar la ocupación de los próximos días.");
        }
    }

    /**
     * Contadores de situaciones que requieren atención.
     * @return array{sin_pago_proximas: int, llegadas_atrasadas: int, salidas_vencidas: int, a_reembolsar: int}
     */
    public function getContadoresAlerta(string $hoy, string $limiteSinPago): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT
                    COALESCE(SUM(r.id_estado_reserva = :pendiente AND r.fecha_entrada BETWEEN :hoy1 AND :limite), 0) AS sin_pago_proximas,
                    COALESCE(SUM(r.id_estado_reserva = :confirmada AND r.fecha_entrada < :hoy2 AND r.fecha_salida > :hoy3), 0) AS llegadas_atrasadas,
                    COALESCE(SUM(r.id_estado_reserva = :en_casa AND r.fecha_salida < :hoy4), 0) AS salidas_vencidas,
                    COALESCE(SUM(rp.id_estado_pago = :a_reembolsar), 0) AS a_reembolsar
                 FROM Reservas r
                 LEFT JOIN Resumen_Pago rp ON rp.id_reserva = r.id"
            );
            $stmt->execute([
                ':pendiente'    => self::ESTADO_PENDIENTE,
                ':confirmada'   => self::ESTADO_CONFIRMADA,
                ':en_casa'      => self::ESTADO_EN_CASA,
                ':a_reembolsar' => ResumenPago::ESTADO_A_REEMBOLSAR,
                ':hoy1' => $hoy, ':hoy2' => $hoy, ':hoy3' => $hoy, ':hoy4' => $hoy,
                ':limite' => $limiteSinPago,
            ]);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return array_map('intval', $fila + [
                'sin_pago_proximas' => 0, 'llegadas_atrasadas' => 0, 'salidas_vencidas' => 0, 'a_reembolsar' => 0,
            ]);
        } catch (PDOException $e) {
            error_log("Error en Reserva::getContadoresAlerta: " . $e->getMessage());
            throw new Exception("Error al consultar las alertas.");
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
            // Se guarda el texto tal cual; el escape se hace al mostrarlo en la vista
            $this->observaciones = trim($observaciones);
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
