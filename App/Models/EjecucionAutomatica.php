<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Tabla Ejecuciones_Automaticas (migración 012): una fila por corrida de cada proceso
 * automático. La escriben los EVENTs de la base y, de respaldo, los cron PHP.
 */
class EjecucionAutomatica extends Model
{
    public function iniciar(string $proceso, string $origen): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO Ejecuciones_Automaticas (proceso, origen) VALUES (:proceso, :origen)"
        );
        $stmt->execute([':proceso' => $proceso, ':origen' => $origen]);
        return (int)$this->db->lastInsertId();
    }

    public function finalizar(int $id, bool $ok, ?int $filas, ?string $error = null): void
    {
        $stmt = $this->db->prepare(
            "UPDATE Ejecuciones_Automaticas
             SET fin = NOW(), ok = :ok, filas_afectadas = :filas, error = :error
             WHERE id = :id"
        );
        $stmt->execute([':ok' => $ok ? 1 : 0, ':filas' => $filas, ':error' => $error, ':id' => $id]);
    }

    /** Última corrida de un proceso (cualquier resultado) y última terminada bien. */
    public function ultimas(string $proceso): array
    {
        $ultima = $this->db->prepare(
            "SELECT * FROM Ejecuciones_Automaticas WHERE proceso = :p ORDER BY inicio DESC, id DESC LIMIT 1"
        );
        $ultima->execute([':p' => $proceso]);

        $ultimaOk = $this->db->prepare(
            "SELECT * FROM Ejecuciones_Automaticas WHERE proceso = :p AND ok = 1 ORDER BY inicio DESC, id DESC LIMIT 1"
        );
        $ultimaOk->execute([':p' => $proceso]);

        return [
            'ultima'    => $ultima->fetch(PDO::FETCH_ASSOC) ?: null,
            'ultima_ok' => $ultimaOk->fetch(PDO::FETCH_ASSOC) ?: null,
        ];
    }

    /** Historial reciente, opcionalmente de un solo proceso. */
    public function listar(?string $proceso, int $limite): array
    {
        $where = $proceso !== null ? 'WHERE proceso = :p' : '';
        $stmt = $this->db->prepare(
            "SELECT * FROM Ejecuciones_Automaticas $where ORDER BY inicio DESC, id DESC LIMIT :limite"
        );
        if ($proceso !== null) {
            $stmt->bindValue(':p', $proceso);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Estado del programador de EVENTs del servidor ('ON', 'OFF' o 'DISABLED'). */
    public function estadoScheduler(): string
    {
        return (string)$this->db->query("SELECT @@GLOBAL.event_scheduler")->fetchColumn();
    }

    /** EVENTs de la base: nombre => [status, created]. */
    public function eventos(): array
    {
        $stmt = $this->db->query(
            "SELECT EVENT_NAME, STATUS, CREATED FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()"
        );
        $eventos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
            $eventos[$e['EVENT_NAME']] = ['status' => $e['STATUS'], 'created' => $e['CREATED']];
        }
        return $eventos;
    }
}
