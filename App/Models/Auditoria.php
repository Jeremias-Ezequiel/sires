<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Tabla Auditoria (migración 013). Escritura y consultas del registro de acciones.
 * La lógica de qué se registra está en App\Services\AuditoriaService.
 */
class Auditoria extends Model
{
    public function insertar(?int $idUsuario, string $accion, string $entidad, ?int $idEntidad,
                             string $descripcion, ?string $antes, ?string $despues, ?string $ip): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO Auditoria (id_usuario, accion, entidad, id_entidad, descripcion, antes, despues, ip)
             VALUES (:usuario, :accion, :entidad, :id_entidad, :descripcion, :antes, :despues, :ip)"
        );
        $stmt->execute([
            ':usuario'     => $idUsuario,
            ':accion'      => $accion,
            ':entidad'     => $entidad,
            ':id_entidad'  => $idEntidad,
            ':descripcion' => mb_substr($descripcion, 0, 255),
            ':antes'       => $antes,
            ':despues'     => $despues,
            ':ip'          => $ip,
        ]);
    }

    /**
     * Listado filtrado y paginado. Filtros (todos opcionales): id_usuario, entidad,
     * id_entidad, accion (prefijo, ej. 'reserva.'), desde, hasta (Y-m-d).
     * @return array{filas: array, total: int}
     */
    public function listar(array $filtros, int $limite, int $offset): array
    {
        [$where, $params] = $this->armarFiltros($filtros);

        $total = $this->db->prepare("SELECT COUNT(*) FROM Auditoria a $where");
        $total->execute($params);

        $sql = "SELECT a.id, a.fecha, a.id_usuario, a.accion, a.entidad, a.id_entidad,
                       a.descripcion, a.antes, a.despues, a.ip,
                       u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
                FROM Auditoria a
                LEFT JOIN Usuarios u ON u.id = a.id_usuario
                $where
                ORDER BY a.fecha DESC, a.id DESC
                LIMIT :limite OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['filas' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => (int)$total->fetchColumn()];
    }

    /** Usuarios que aparecen en la auditoría, para el filtro. */
    public function usuariosConRegistros(): array
    {
        $stmt = $this->db->query(
            "SELECT DISTINCT u.id, u.nombre, u.apellido
             FROM Auditoria a JOIN Usuarios u ON u.id = a.id_usuario
             ORDER BY u.apellido, u.nombre"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Catálogos para mostrar nombres en lugar de ids en los cambios.
     * @return array<string, array<int, string>>
     */
    public function catalogos(): array
    {
        $consultas = [
            'id_estado_reserva'    => "SELECT id, descripcion FROM Estados_Reserva",
            'id_estado_habitacion' => "SELECT id, descripcion FROM Estados_Habitacion",
            'id_estado_pago'       => "SELECT id, descripcion FROM Estados_Pago",
            'id_tipo_habitacion'   => "SELECT id, descripcion FROM Tipos_Habitacion",
            'id_motivo_bloqueo'    => "SELECT id, descripcion FROM Motivos_Bloqueo",
            'id_rol'               => "SELECT id, descripcion FROM Roles",
            'id_habitacion'        => "SELECT id, CONCAT('#', numero) FROM Habitaciones",
        ];
        $catalogos = [];
        foreach ($consultas as $campo => $sql) {
            $catalogos[$campo] = $this->db->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        }
        return $catalogos;
    }

    private function armarFiltros(array $f): array
    {
        $cond = [];
        $params = [];
        if (!empty($f['id_usuario'])) {
            $cond[] = 'a.id_usuario = :usuario';
            $params[':usuario'] = (int)$f['id_usuario'];
        }
        if (!empty($f['entidad'])) {
            $cond[] = 'a.entidad = :entidad';
            $params[':entidad'] = (string)$f['entidad'];
        }
        if (!empty($f['id_entidad'])) {
            $cond[] = 'a.id_entidad = :id_entidad';
            $params[':id_entidad'] = (int)$f['id_entidad'];
        }
        if (!empty($f['accion'])) {
            $cond[] = 'a.accion LIKE :accion';
            $params[':accion'] = str_replace(['%', '_'], ['\%', '\_'], (string)$f['accion']) . '%';
        }
        if (!empty($f['desde'])) {
            $cond[] = 'a.fecha >= :desde';
            $params[':desde'] = $f['desde'] . ' 00:00:00';
        }
        if (!empty($f['hasta'])) {
            $cond[] = 'a.fecha <= :hasta';
            $params[':hasta'] = $f['hasta'] . ' 23:59:59';
        }
        return [$cond ? 'WHERE ' . implode(' AND ', $cond) : '', $params];
    }
}
