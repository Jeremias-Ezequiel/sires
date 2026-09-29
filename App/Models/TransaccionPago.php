<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class TransaccionPago extends Model
{
    private int $id;
    private int $id_resumen_pago;
    private int $id_metodo_pago;
    private float $monto_abonado;
    private string $fecha_hora;
    private int $registrado_por;
    private ?string $idempotency_key = null;

    public const ESTADO_PENDIENTE = 1;
    public const ESTADO_PAGO_PARCIAL = 2;
    public const ESTADO_PAGADO_TOTAL = 3;
    public const ESTADO_REEMBOLSADO = 4;

    public function sumIngresosDelDia(?string $fecha = null): float
    {
        $fecha = $fecha ?? date('Y-m-d');

        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(tp.monto_abonado), 0)
                FROM Transacciones_Pago tp
                INNER JOIN Resumen_Pago rp ON tp.id_resumen_pago = rp.id
                WHERE DATE(tp.fecha_hora) = :fecha 
                AND rp.id_estado_pago <> :reembolsado"
            );
            $stmt->execute([
                ':fecha'        => $fecha,
                ':reembolsado'  => self::ESTADO_REEMBOLSADO
            ]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error en TransaccionPago::sumIngresosDelDia: " . $e->getMessage());
            throw new Exception("Error al consultar los ingresos del día.");
        }
    }

    public function save(TransaccionPago $transaccion): bool
    {
        try {
            if ($transaccion->getMontoAbonado() <= 0) {
                throw new Exception("El monto a abonar debe ser mayor a 0.");
            }

            $sql = "INSERT INTO Transacciones_Pago (id_resumen_pago, id_metodo_pago, monto_abonado, registrado_por, idempotency_key)
                    VALUES (:id_resumen_pago, :id_metodo_pago, :monto_abonado, :registrado_por, :idempotency_key)";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id_resumen_pago'  => $transaccion->getIdResumenPago(),
                ':id_metodo_pago'   => $transaccion->getIdMetodoPago(),
                ':monto_abonado'    => $transaccion->getMontoAbonado(),
                ':registrado_por'   => $transaccion->getRegistradoPor(),
                ':idempotency_key'  => $transaccion->getIdempotencyKey()
            ]);
        } catch (PDOException $e) {
            // 23000 = violation de integridad. La unica que puede aparecer aca en
            // la practica es el indice unico ux_transacciones_idempotencia: el
            // cliente reenvio el formulario (doble click, F5, respuesta perdida).
            // Es justo lo que la clave debe evitar, asi que no es una falla del
            // servidor sino un reenvio del usuario.
            if (self::esClaveDuplicada($e)) {
                error_log("Pago duplicado bloqueado por idempotency_key: " . $e->getMessage());
                throw new Exception("Este pago ya fue registrado. No se cobró dos veces.");
            }

            error_log("Error en TransaccionPago::save: " . $e->getMessage());
            throw new Exception("Error interno al registrar la transacción.");
        }
    }

    /**
     * SQLSTATE 23000 con codigo MySQL 1062 = valor duplicado en indice unico.
     */
    public static function esClaveDuplicada(PDOException $e): bool
    {
        return $e->getCode() === '23000'
            && isset($e->errorInfo[1])
            && (int)$e->errorInfo[1] === 1062;
    }

    /**
     * Chequeo previo del indice unico, para dar un mensaje claro en el caso
     * normal de un reenvio del formulario. El indice unico sigue siendo la
     * garantia real contra dos requests simultaneos con la misma clave.
     */
    public function existeClave(string $idempotency_key): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM Transacciones_Pago WHERE idempotency_key = :clave LIMIT 1"
            );
            $stmt->execute([':clave' => $idempotency_key]);
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            error_log("Error en TransaccionPago::existeClave: " . $e->getMessage());
            return false;
        }
    }

    public function getByResumenPago(int $id_resumen_pago): array
    {
        try {
            $sql = "SELECT tp.id, tp.id_resumen_pago, tp.id_metodo_pago,
                           tp.monto_abonado, tp.fecha_hora, tp.registrado_por,
                           mp.descripcion AS metodo_pago_descripcion,
                           u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
                    FROM Transacciones_Pago tp
                    JOIN Metodos_Pago mp ON tp.id_metodo_pago = mp.id
                    JOIN Usuarios u ON tp.registrado_por = u.id
                    WHERE tp.id_resumen_pago = :id_resumen_pago
                    ORDER BY tp.fecha_hora DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id_resumen_pago' => $id_resumen_pago]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error en TransaccionPago::getByResumenPago: " . $e->getMessage());
            throw new Exception("Error en la base de datos al listar las transacciones de pago.");
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

    public function getIdResumenPago(): int
    {
        return $this->id_resumen_pago;
    }
    public function setIdResumenPago(int $id_resumen_pago): void
    {
        if ($id_resumen_pago <= 0) {
            throw new Exception("El resumen de pago no es válido.");
        }
        $this->id_resumen_pago = $id_resumen_pago;
    }

    public function getIdMetodoPago(): int
    {
        return $this->id_metodo_pago;
    }
    public function setIdMetodoPago(int $id_metodo_pago): void
    {
        if ($id_metodo_pago <= 0) {
            throw new Exception("El método de pago no es válido.");
        }
        $this->id_metodo_pago = $id_metodo_pago;
    }

    public function getMontoAbonado(): float
    {
        return $this->monto_abonado;
    }
    public function setMontoAbonado(float $monto_abonado): void
    {
        if ($monto_abonado <= 0) {
            throw new Exception("El monto a abonar debe ser mayor a 0.");
        }
        $this->monto_abonado = $monto_abonado;
    }

    public function getFechaHora(): string
    {
        return $this->fecha_hora;
    }
    public function setFechaHora(string $fecha_hora): void
    {
        $this->fecha_hora = $fecha_hora;
    }

    public function getRegistradoPor(): int
    {
        return $this->registrado_por;
    }
    public function setRegistradoPor(int $registrado_por): void
    {
        if ($registrado_por <= 0) {
            throw new Exception("El usuario registrador no es válido.");
        }
        $this->registrado_por = $registrado_por;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotency_key;
    }

    /**
     * La clave viene del navegador, asi que no se confía en ella: solo se
     * aceptan hex de 32 a 64 caracteres, que es lo que genera
     * bin2hex(random_bytes(16)) (32) con margen para ampliarlo.
     * Cualquier otra cosa es NULL, y NULL no activa el indice unico, asi que
     * un cliente hostil no puede romper el sistema mandando basura.
     */
    public function setIdempotencyKey(?string $idempotency_key): void
    {
        if ($idempotency_key === null) {
            $this->idempotency_key = null;
            return;
        }

        $limpia = strtolower(trim($idempotency_key));

        if (!preg_match('/^[0-9a-f]{32,64}$/', $limpia)) {
            $this->idempotency_key = null;
            return;
        }

        $this->idempotency_key = $limpia;
    }
}
