<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Tabla Cola_Mails (migración 014): mails al huésped pendientes, enviados o fallidos.
 * Las fechas se escriben con la hora de PHP (zona del hotel), no con NOW() de la base.
 */
class ColaMail extends Model
{
    public const TIPO_CONFIRMACION = 'confirmacion';
    public const TIPO_RECORDATORIO = 'recordatorio';
    public const TIPO_COMPROBANTE_PAGO = 'comprobante_pago';

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_ENVIADO = 'enviado';
    public const ESTADO_ERROR = 'error';

    /** Nombre visible de cada tipo. */
    public const TIPOS = [
        self::TIPO_CONFIRMACION    => 'Confirmación de reserva',
        self::TIPO_RECORDATORIO    => 'Recordatorio de llegada',
        self::TIPO_COMPROBANTE_PAGO => 'Comprobante de pago',
    ];

    /** Encola un mail. Devuelve false si ya existía uno con la misma clave_unica. */
    public function encolar(string $tipo, ?int $idReserva, string $clave, string $destinatario, string $asunto, string $html): bool
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO Cola_Mails
                 (tipo, id_reserva, clave_unica, destinatario, asunto, cuerpo_html, proximo_intento, fecha_alta)
             VALUES (:tipo, :reserva, :clave, :destinatario, :asunto, :html, :ahora, :ahora2)"
        );
        $ahora = date('Y-m-d H:i:s');
        $stmt->execute([
            ':tipo' => $tipo, ':reserva' => $idReserva, ':clave' => $clave,
            ':destinatario' => $destinatario, ':asunto' => $asunto, ':html' => $html,
            ':ahora' => $ahora, ':ahora2' => $ahora,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> Pendientes cuyo próximo intento ya llegó, los más viejos primero. */
    public function listosParaEnviar(int $limite): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, tipo, id_reserva, destinatario, asunto, cuerpo_html, intentos
             FROM Cola_Mails
             WHERE estado = :pendiente AND proximo_intento <= :ahora
             ORDER BY proximo_intento, id
             LIMIT :limite"
        );
        $stmt->bindValue(':pendiente', self::ESTADO_PENDIENTE);
        $stmt->bindValue(':ahora', date('Y-m-d H:i:s'));
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function marcarEnviado(int $id): void
    {
        $this->db->prepare(
            "UPDATE Cola_Mails SET estado = :enviado, intentos = intentos + 1, fecha_envio = :ahora,
                    ultimo_error = NULL, proximo_intento = NULL
             WHERE id = :id"
        )->execute([':enviado' => self::ESTADO_ENVIADO, ':ahora' => date('Y-m-d H:i:s'), ':id' => $id]);
    }

    /** Registra un intento fallido. Sin $proximoIntento el mail queda en 'error' (no se reintenta). */
    public function registrarFallo(int $id, int $intentos, ?string $proximoIntento, string $error): void
    {
        $this->db->prepare(
            "UPDATE Cola_Mails SET intentos = :intentos, ultimo_error = :error,
                    estado = :estado, proximo_intento = :proximo
             WHERE id = :id"
        )->execute([
            ':intentos' => $intentos,
            ':error'    => $error,
            ':estado'   => $proximoIntento === null ? self::ESTADO_ERROR : self::ESTADO_PENDIENTE,
            ':proximo'  => $proximoIntento,
            ':id'       => $id,
        ]);
    }

    /** @return list<array<string, mixed>> Mails de una reserva (sin el cuerpo), los más nuevos primero. */
    public function porReserva(int $idReserva): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, tipo, destinatario, asunto, estado, intentos, ultimo_error, fecha_alta, fecha_envio
             FROM Cola_Mails WHERE id_reserva = :id ORDER BY id DESC"
        );
        $stmt->execute([':id' => $idReserva]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pendientes encolados hace más de $minutos (la cola no se está procesando). */
    public function pendientesDemorados(int $minutos): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM Cola_Mails
             WHERE estado = :pendiente AND intentos = 0 AND fecha_alta < :limite"
        );
        $stmt->bindValue(':pendiente', self::ESTADO_PENDIENTE);
        $stmt->bindValue(':limite', date('Y-m-d H:i:s', time() - $minutos * 60));
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
}
