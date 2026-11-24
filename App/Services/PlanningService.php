<?php

declare(strict_types=1);

namespace App\Services;

use App\Dominio\ReglasReserva;
use App\Config\Database;
use App\Dominio\Planning;
use App\Models\Habitacion;
use PDO;

/**
 * Datos del planning de ocupación: habitaciones activas en filas y reservas como barras.
 * Los cálculos (rango, posición de barras, estilo por estado) están en App\Dominio\Planning.
 */
final class PlanningService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = (new Database())->getConnection();
    }

    /**
     * @return array{
     *     desde: string, dias: int, hoy: string, anterior: string, siguiente: string,
     *     columnas: list<array<string, mixed>>, habitaciones: list<array<string, mixed>>
     * }
     */
    public function obtener(mixed $desde, mixed $dias): array
    {
        $hoy   = date('Y-m-d');
        $dias  = Planning::normalizarDias($dias);
        $desde = Planning::normalizarDesde($desde, $hoy);
        $hasta = Planning::desplazar($desde, $dias);

        $habitaciones = [];
        foreach ($this->habitaciones() as $h) {
            $h['reservable'] = ReglasReserva::habitacionReservable((int)$h['id_estado_habitacion']);
            $h['barras'] = [];
            $habitaciones[(int)$h['id']] = $h;
        }

        foreach ($this->reservas($desde, $hasta) as $r) {
            $id = (int)$r['id_habitacion'];
            $salida = Planning::salidaDibujada((string)$r['fecha_salida'], $r['fecha_checkout'] ?? null);
            $barra = Planning::barra((string)$r['fecha_entrada'], $salida, $desde, $dias);
            if (!isset($habitaciones[$id]) || $barra === null) {
                continue;
            }
            $estilo = Planning::estilo((int)$r['id_estado_reserva'], (string)$r['fecha_salida'], $hoy);
            $habitaciones[$id]['barras'][] = $barra + [
                'id'        => (int)$r['id'],
                'huesped'   => trim($r['apellido'] . ', ' . $r['nombre']),
                'huespedes' => (int)$r['cantidad_huespedes'],
                'entrada'   => (string)$r['fecha_entrada'],
                'salida'    => $salida,
                'estilo'    => $estilo,
                'estado'    => Planning::ESTILOS[$estilo][0],
                'icono'     => Planning::ESTILOS[$estilo][1],
            ];
        }

        return [
            'desde'        => $desde,
            'dias'         => $dias,
            'hoy'          => $hoy,
            'anterior'     => Planning::desplazar($desde, -$dias),
            'siguiente'    => $hasta,
            'columnas'     => Planning::dias($desde, $dias, $hoy),
            'habitaciones' => array_values($habitaciones),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function habitaciones(): array
    {
        $stmt = $this->db->prepare(
            "SELECT h.id, h.numero, h.piso, h.id_estado_habitacion,
                    th.descripcion AS tipo, eh.descripcion AS estado
             FROM Habitaciones h
             JOIN Tipos_Habitacion th ON th.id = h.id_tipo_habitacion
             JOIN Estados_Habitacion eh ON eh.id = h.id_estado_habitacion
             WHERE h.is_active = :activa
             ORDER BY h.piso, CAST(h.numero AS UNSIGNED), h.numero"
        );
        $stmt->execute([':activa' => Habitacion::ACTIVE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> Reservas que ocupan alguna noche de [desde, hasta). */
    private function reservas(string $desde, string $hasta): array
    {
        $estados = Planning::ESTADOS_VISIBLES;
        $marcas = implode(', ', array_map(fn (int $i): string => ':e' . $i, array_keys($estados)));
        $stmt = $this->db->prepare(
            "SELECT r.id, r.id_habitacion, r.id_estado_reserva, r.fecha_entrada, r.fecha_salida,
                    r.fecha_checkout, r.cantidad_huespedes, c.nombre, c.apellido
             FROM Reservas r
             JOIN Clientes c ON c.id = r.id_cliente
             WHERE r.fecha_entrada < :hasta AND r.fecha_salida > :desde
               AND r.id_estado_reserva IN ({$marcas})
             ORDER BY r.fecha_entrada"
        );
        $params = [':desde' => $desde, ':hasta' => $hasta];
        foreach ($estados as $i => $estado) {
            $params[':e' . $i] = $estado;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
