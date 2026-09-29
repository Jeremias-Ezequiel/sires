<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class ResumenPago extends Model
{
    private int $id = 0;
    private int $id_reserva = 0;
    private int $id_estado_pago = 0;
    private float $monto_total = 0.0;
    private float $monto_cobrado = 0.0;
    private float $saldo_pendiente = 0.0;

    // Desglose del total. monto_total SIEMPRE es la suma de los dos. Antes
    // estas dos columnas no las escribia nadie: quedaban en 0.00 y el "total"
    // no se podia explicar. Con el CHECK de la base, un save/update que no
    // las llene revienta con un error de SQL opaco, asi que se llenan aqui.
    private float $monto_hospedaje = 0.0;
    private float $monto_consumos = 0.0;

    public const ESTADO_PENDIENTE = 1;
    public const ESTADO_PAGO_PARCIAL = 2;
    public const ESTADO_PAGADO_TOTAL = 3;
    public const ESTADO_REEMBOLSADO = 4;

    /**
     * Reparte un total entre hospedaje y consumos.
     *
     * El CHECK de la base (monto_total = monto_hospedaje + monto_consumos) es
     * la ultima linea de defensa. Esta funcion es la primera: si el desglose
     * no cierra, se avisa con un mensaje claro en vez de dejar que reviente un
     * INSERT con un error 3819 que nadie entiende.
     *
     * Se redondea a dos decimales porque los DECIMAL(12,2) de MySQL guardan
     * centavos: sin round, 0.1+0.2Stored = 0.30000000000000004 y el CHECK
     * rechazaria una operacion aritmeticamente correcta.
     */
    private function aplicarDesglose(float $hospedaje, float $consumos, float $total): void
    {
        $total = round($total, 2);
        $hospedaje = round($hospedaje, 2);
        $consumos = round($consumos, 2);

        if (abs(round($hospedaje + $consumos, 2) - $total) > 0.009) {
            throw new Exception(
                "El desglose del resumen de pago no cierra: hospedaje $"
                . number_format($hospedaje, 2, ',', '.') . " + consumos $"
                . number_format($consumos, 2, ',', '.') . " no suma el total $"
                . number_format($total, 2, ',', '.') . "."
            );
        }

        $this->monto_hospedaje = $hospedaje;
        $this->monto_consumos  = $consumos;
        $this->monto_total     = $total;
    }

    /**
     * Calcular el desglose de una reserva a partir de su fila.
     *
     * Hospedaje: precio por noche ya descontado por ocupacion, por la cantidad
     * de noches. Consumos: la suma de los consumos cargados a la reserva.
     *
     * @return array{hospedaje: float, consumos: float, total: float}
     */
    public function calcularDesglose(array $reserva): array
    {
        $entrada = new \DateTime($reserva['fecha_entrada']);
        $salida  = new \DateTime($reserva['fecha_salida']);

        // diff() da la diferencia en valor absoluto: con la salida antes que la
        // entrada calcularia unas noches positivas y un hospedaje fantasy.
        if ($salida <= $entrada) {
            throw new Exception(
                "La reserva no tiene fechas válidas para calcular el total: "
                . "la salida tiene que ser posterior a la entrada."
            );
        }

        $noches = $entrada->diff($salida)->days;

        $precioNoche = Habitacion::precioNocheParaTipo(
            (int)$reserva['id_tipo_habitacion'],
            (float)$reserva['precio_noche_base'],
            (int)$reserva['cantidad_huespedes']
        );

        $hospedaje = round($precioNoche * $noches, 2);
        $consumos  = $this->totalConsumosDe((int)$reserva['id']);

        return [
            'hospedaje' => $hospedaje,
            'consumos'  => $consumos,
            'total'     => round($hospedaje + $consumos, 2),
        ];
    }

    /**
     * Estados de Consumos.id_estado: 1 pendiente, 2 facturado, 3 anulado.
     *
     * Solo lo facturado entra en el total. Sumar los pendientes hacia que la
     * reserva debe plata por consumos que todavia no se cobraron, y esos
     * pueden anularse despues sin que el total baje.
     */
    public const CONSUMO_PENDIENTE  = 1;
    public const CONSUMO_FACTURADO  = 2;
    public const CONSUMO_ANULADO    = 3;

    /**
     * Suma de los consumos facturados de una reserva.
     *
     * El filtro por id_estado = 2 (facturado) tiene que coincidir con el de los
     * triggers trg_consumos_* de la base, que recalculan monto_consumos solo
     * con los facturados. Si el PHP sumara los pendientes, el total del resumen
     * y el que mantienen los triggers no congenian.
     */
    public function totalConsumosDe(int $idReserva): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(subtotal), 0) FROM Consumos
                 WHERE id_reserva = ? AND id_estado = ?"
            );
            $stmt->execute([$idReserva, self::CONSUMO_FACTURADO]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::totalConsumosDe: " . $e->getMessage());
            throw new Exception("Error al calcular los consumos de la reserva.");
        }
    }

    public function getByReserva(int $id_reserva): ?ResumenPago
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM Resumen_Pago WHERE id_reserva = :id_reserva");
            $stmt->execute([':id_reserva' => $id_reserva]);

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, ResumenPago::class);
            $resumen = $stmt->fetch();

            return $resumen ?: null;
        } catch (PDOException $e) {
            error_log("Error in getByReserva resumen: " . $e->getMessage());
            throw new Exception("Database error during resumen lookup.");
        }
    }

    public function getByReservaForUpdate(int $id_reserva): ?ResumenPago
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM Resumen_Pago WHERE id_reserva = :id_reserva FOR UPDATE");
            $stmt->execute([':id_reserva' => $id_reserva]);

            $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, ResumenPago::class);
            $resumen = $stmt->fetch();

            return $resumen ?: null;
        } catch (PDOException $e) {
            error_log("Error in getByReservaForUpdate resumen: " . $e->getMessage());
            throw new Exception("Database error during resumen lookup.");
        }
    }

    public function save(ResumenPago $resumen): bool
    {
        try {
            $check = $this->db->prepare("SELECT COUNT(*) FROM Resumen_Pago WHERE id_reserva = :id_reserva");
            $check->execute([':id_reserva' => $resumen->getIdReserva()]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception("La reserva ya tiene un resumen de pago asociado.");
            }

            $sql = "INSERT INTO Resumen_Pago (id_reserva, id_estado_pago, monto_total, monto_hospedaje, monto_consumos, monto_cobrado, saldo_pendiente)
                    VALUES (:id_reserva, :id_estado_pago, :monto_total, :monto_hospedaje, :monto_consumos, :monto_cobrado, :saldo_pendiente)";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id_reserva'       => $resumen->getIdReserva(),
                ':id_estado_pago'   => $resumen->getIdEstadoPago(),
                ':monto_total'      => $resumen->getTotal(),
                ':monto_hospedaje'  => $resumen->getMontoHospedaje(),
                ':monto_consumos'   => $resumen->getMontoConsumos(),
                ':monto_cobrado'    => $resumen->getMontoPagado(),
                ':saldo_pendiente'  => $resumen->getSaldoPendiente()
            ]);
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::save: " . $e->getMessage());
            if ($e->getCode() === '23000') {
                throw new Exception("La reserva ya tiene un resumen de pago.");
            }
            throw new Exception("Error interno al guardar el resumen de pago.");
        }
    }

    public function update(ResumenPago $resumen): bool
    {
        try {
            $sql = "UPDATE Resumen_Pago
                    SET id_estado_pago = :id_estado_pago,
                        monto_total    = :monto_total,
                        monto_hospedaje = :monto_hospedaje,
                        monto_consumos = :monto_consumos,
                        monto_cobrado  = :monto_cobrado,
                        saldo_pendiente = :saldo_pendiente
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id'                => $resumen->getId(),
                ':id_estado_pago'    => $resumen->getIdEstadoPago(),
                ':monto_total'       => $resumen->getTotal(),
                ':monto_hospedaje'   => $resumen->getMontoHospedaje(),
                ':monto_consumos'    => $resumen->getMontoConsumos(),
                ':monto_cobrado'     => $resumen->getMontoPagado(),
                ':saldo_pendiente'   => $resumen->getSaldoPendiente()
            ]);
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::update: " . $e->getMessage());
            throw new Exception("Error interno al actualizar el resumen de pago.");
        }
    }

    public function listPagos(?string $search, ?string $estadoPago, int $limit, int $offset): array
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $conditions[] = "(c.nombre LIKE :search OR c.apellido LIKE :search2 OR h.numero LIKE :search3)";
            $params['search'] = "%" . $search . "%";
            $params['search2'] = "%" . $search . "%";
            $params['search3'] = "%" . $search . "%";
        }

        if ($estadoPago !== null && $estadoPago !== '') {
            $conditions[] = "rp.id_estado_pago = :estado_pago";
            $params['estado_pago'] = (int)$estadoPago;
        }

        $sql = "SELECT r.id, r.id_estado_reserva, r.fecha_entrada, r.fecha_salida,
                       c.nombre AS cliente_nombre, c.apellido AS cliente_apellido,
                       h.numero AS habitacion_numero,
                       er.descripcion AS estado_descripcion,
                       rp.id AS id_resumen_pago,
                       rp.id_estado_pago, rp.monto_total, rp.monto_cobrado, rp.saldo_pendiente,
                       ep.descripcion AS estado_pago_descripcion
                FROM Reservas r
                JOIN Clientes c ON r.id_cliente = c.id
                JOIN Habitaciones h ON r.id_habitacion = h.id
                JOIN Estados_Reserva er ON r.id_estado_reserva = er.id
                LEFT JOIN Resumen_Pago rp ON rp.id_reserva = r.id
                LEFT JOIN Estados_Pago ep ON rp.id_estado_pago = ep.id";

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
            error_log("Error en ResumenPago::listPagos: " . $e->getMessage());
            throw new Exception("Error en la base de datos al buscar pagos.");
        }
    }

    public function countPagos(?string $search, ?string $estadoPago): int
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $conditions[] = "(c.nombre LIKE :search OR c.apellido LIKE :search2 OR h.numero LIKE :search3)";
            $params['search'] = "%" . $search . "%";
            $params['search2'] = "%" . $search . "%";
            $params['search3'] = "%" . $search . "%";
        }

        if ($estadoPago !== null && $estadoPago !== '') {
            $conditions[] = "rp.id_estado_pago = :estado_pago";
            $params['estado_pago'] = (int)$estadoPago;
        }

        $sql = "SELECT COUNT(*)
                FROM Reservas r
                JOIN Clientes c ON r.id_cliente = c.id
                JOIN Habitaciones h ON r.id_habitacion = h.id
                LEFT JOIN Resumen_Pago rp ON rp.id_reserva = r.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::countPagos: " . $e->getMessage());
            return 0;
        }
    }

    public function getEstadosPago(): array
    {
        try {
            $stmt = $this->db->query("SELECT id, descripcion FROM Estados_Pago ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::getEstadosPago: " . $e->getMessage());
            return [];
        }
    }

    public function reembolsarPorReserva(int $id_reserva): bool
    {
        try {
            $resumen = $this->getByReserva($id_reserva);
            if ($resumen === null) {
                throw new Exception("La reserva no tiene un resumen de pago asociado.");
            }

            $sql = "UPDATE Resumen_Pago
                    SET id_estado_pago = :estado,
                        monto_cobrado  = :cobrado,
                        saldo_pendiente = :saldo
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':estado'  => self::ESTADO_REEMBOLSADO,
                ':cobrado' => 0.0,
                ':saldo'   => 0.0,
                ':id'      => $resumen->getId()
            ]);
        } catch (PDOException $e) {
            error_log("Error en ResumenPago::reembolsarPorReserva: " . $e->getMessage());
            throw new Exception("Error interno al reembolsar el pago.");
        }
    }

    public function recalcular(array $reserva): bool
    {
        $resumen = $this->getByReserva((int)$reserva['id']);
        if ($resumen === null) {
            return false;
        }

        $entrada = new \DateTime($reserva['fecha_entrada']);
        $salida  = new \DateTime($reserva['fecha_salida']);
        $noches  = $entrada->diff($salida)->days;

        // El total ya no es solo el hospedaje: tambien entra lo que la reserva
        // consumio. Antes, recargar consumos a una reserva no movia el total.
        $desglose = $this->calcularDesglose($reserva);
        $nuevoTotal = $desglose['total'];
        $montoPagado = $resumen->getMontoPagado();
        $nuevoSaldo = $nuevoTotal - $montoPagado;

        if ($nuevoTotal <= 0) {
            throw new Exception("No se pudo calcular un total válido para la reserva.");
        }

        if ($nuevoSaldo < 0) {
            throw new Exception(
                "El nuevo total ($" . number_format($nuevoTotal, 2, ',', '.') .
                ") es menor a lo ya cobrado ($" . number_format($montoPagado, 2, ',', '.') .
                "). No se puede recalcular la reserva."
            );
        }

        if ($resumen->getIdEstadoPago() !== self::ESTADO_REEMBOLSADO) {
            if ($nuevoSaldo <= 0 && $montoPagado > 0) {
                $resumen->setIdEstadoPago(self::ESTADO_PAGADO_TOTAL);
            } elseif ($montoPagado > 0) {
                $resumen->setIdEstadoPago(self::ESTADO_PAGO_PARCIAL);
            } else {
                $resumen->setIdEstadoPago(self::ESTADO_PENDIENTE);
            }
        }

        // Ojo: el desglose se fija sobre $resumen, no sobre $this. update()
        // lee los montos del objeto que recibe, asi que cambiar $this guardaria
        // el desglose viejo y el CHECK de la base veria el reparto anterior.
        $resumen->setDesglose($desglose['hospedaje'], $desglose['consumos']);
        $resumen->setSaldoPendiente($nuevoSaldo);

        return $this->update($resumen);
    }

    public function getId(): int
    {
        return $this->id;
    }
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getIdReserva(): int
    {
        return $this->id_reserva;
    }
    public function setIdReserva(int $id_reserva): void
    {
        if ($id_reserva <= 0) {
            throw new Exception("La reserva vinculada no es válida.");
        }
        $this->id_reserva = $id_reserva;
    }

    public function getIdEstadoPago(): int
    {
        return $this->id_estado_pago;
    }
    public function setIdEstadoPago(int $id_estado_pago): void
    {
        if ($id_estado_pago <= 0) {
            throw new Exception("El estado de pago no es válido.");
        }
        $this->id_estado_pago = $id_estado_pago;
    }

    public function getTotal(): float
    {
        return $this->monto_total;
    }

    /**
     * Seteo del total "a pelo".
     *
     * Solo se usa al hidratar una fila que viene de la base (FETCH_CLASS), donde
     * el CHECK ya garantiza que el desglose cierra. Para calcular un total
     * nuevo hay que usar aplicarDesglose() con su reparto, o el CHECK va a
     * rechazar el INSERT por desglose inconsistente.
     */
    public function setTotal(float $total): void
    {
        if ($total < 0) {
            throw new Exception("El total no puede ser negativo.");
        }
        $this->monto_total = $total;
    }

    public function getMontoHospedaje(): float
    {
        return $this->monto_hospedaje;
    }
    public function setMontoHospedaje(float $monto): void
    {
        if ($monto < 0) {
            throw new Exception("El monto de hospedaje no puede ser negativo.");
        }
        $this->monto_hospedaje = $monto;
    }

    public function getMontoConsumos(): float
    {
        return $this->monto_consumos;
    }
    public function setMontoConsumos(float $monto): void
    {
        if ($monto < 0) {
            throw new Exception("El monto de consumos no puede ser negativo.");
        }
        $this->monto_consumos = $monto;
    }

    /**
     * Fija el desglose completo de una vez.
     *
     * Es el metodo que deberia usar el codigo: garantiza que los tres montos
     * queden consistentes entre si.
     *
     * @return array{hospedaje: float, consumos: float, total: float}
     */
    public function setDesglose(float $hospedaje, float $consumos): array
    {
        $this->aplicarDesglose($hospedaje, $consumos, $hospedaje + $consumos);

        return [
            'hospedaje' => $this->monto_hospedaje,
            'consumos'  => $this->monto_consumos,
            'total'     => $this->monto_total,
        ];
    }

    public function getMontoPagado(): float
    {
        return $this->monto_cobrado;
    }
    public function setMontoPagado(float $monto_pagado): void
    {
        if ($monto_pagado < 0) {
            throw new Exception("El monto pagado no puede ser negativo.");
        }
        $this->monto_cobrado = $monto_pagado;
    }

    public function getSaldoPendiente(): float
    {
        return $this->saldo_pendiente;
    }
    public function setSaldoPendiente(float $saldo_pendiente): void
    {
        if ($saldo_pendiente < 0) {
            throw new Exception("El saldo pendiente no puede ser negativo.");
        }
        $this->saldo_pendiente = $saldo_pendiente;
    }
}