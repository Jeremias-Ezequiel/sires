<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Habitacion;
use App\Models\Reserva;
use App\Models\ResumenPago;
use App\Models\TransaccionPago;

/**
 * Arma los datos del tablero de inicio: KPIs del día, llegadas y salidas con la
 * acción que corresponde a cada una, estado de las habitaciones, alertas y
 * ocupación de los próximos días. Solo lectura.
 */
class DashboardService
{
    /** Días hacia adelante para avisar de reservas pendientes de pago. */
    private const DIAS_AVISO_SIN_PAGO = 3;

    /** Días que muestra el gráfico de ocupación. */
    private const DIAS_PROYECCION = 7;

    private Reserva $reservaModel;
    private Habitacion $habitacionModel;
    private TransaccionPago $transaccionModel;

    public function __construct()
    {
        $this->reservaModel     = new Reserva();
        $this->habitacionModel  = new Habitacion();
        $this->transaccionModel = new TransaccionPago();
    }

    public function obtenerTablero(): array
    {
        $hoy = date('Y-m-d');

        $llegadas = array_map(fn(array $r) => $r + ['accion' => $this->accionLlegada($r)],
            $this->reservaModel->getLlegadasDelDia($hoy));
        $salidas = array_map(fn(array $r) => $r + ['accion' => $this->accionSalida($r)],
            $this->reservaModel->getSalidasDelDia($hoy));

        $llegaHoy = [];
        foreach ($llegadas as $l) {
            if ((int)$l['id_estado_reserva'] !== Reserva::ESTADO_EN_CASA) {
                $llegaHoy[(int)$l['id_habitacion']] = trim($l['cliente_nombre'] . ' ' . $l['cliente_apellido']);
            }
        }

        $habitaciones = array_map(fn(array $h) => $h + $this->estadoVisual($h, $hoy, $llegaHoy),
            $this->habitacionModel->getTablero());

        $conteo = array_count_values(array_column($habitaciones, 'visual'));
        $operativas = count(array_filter($habitaciones, fn($h) => $h['visual'] !== 'mantenimiento'));
        $ocupadas = ($conteo['ocupada'] ?? 0) + ($conteo['checkout'] ?? 0);
        $porLimpiar = $conteo['limpieza'] ?? 0;
        $fueraServicio = $conteo['mantenimiento'] ?? 0;

        $pendientesCheckin = count(array_filter($llegadas, fn($l) => $l['accion'] !== 'hecho'));
        $pendientesCheckout = count(array_filter($salidas, fn($s) => $s['accion'] !== 'hecho'));

        // Sucias o en limpieza con un huésped que llega hoy: lo más urgente para housekeeping
        $suciasConLlegada = count(array_filter($habitaciones,
            fn($h) => $h['visual'] === 'limpieza' && isset($llegaHoy[(int)$h['id']])));

        $limiteSinPago = date('Y-m-d', strtotime("+" . self::DIAS_AVISO_SIN_PAGO . " days"));
        $contadores = $this->reservaModel->getContadoresAlerta($hoy, $limiteSinPago);

        return [
            'hoy'          => $hoy,
            'horaCheckout' => ConfiguracionService::horaCheckout(),
            'kpis' => [
                'llegadas'            => count($llegadas),
                'llegadas_pendientes' => $pendientesCheckin,
                'salidas'             => count($salidas),
                'salidas_pendientes'  => $pendientesCheckout,
                'ocupacion_pct'       => $operativas > 0 ? (int)round($ocupadas * 100 / $operativas) : 0,
                'ocupadas'            => $ocupadas,
                'operativas'          => $operativas,
                'disponibles'         => $conteo['disponible'] ?? 0,
                'reservadas_hoy'      => $conteo['reservada'] ?? 0,
                'por_limpiar'         => $porLimpiar,
                'cobrado_hoy'         => $this->transaccionModel->sumIngresosDelDia($hoy),
            ],
            'llegadas'     => $llegadas,
            'salidas'      => $salidas,
            'habitaciones' => $habitaciones,
            'conteo'       => $conteo,
            'alertas'      => $this->armarAlertas($contadores, $porLimpiar, $fueraServicio, $suciasConLlegada),
            'proyeccion'   => $this->proyeccionOcupacion($hoy, $operativas),
        ];
    }

    /** 'checkin' | 'cobrar' | 'hecho' | 'ver' según el estado de la reserva y su pago. */
    private function accionLlegada(array $r): string
    {
        $estado = (int)$r['id_estado_reserva'];
        if ($estado === Reserva::ESTADO_EN_CASA) {
            return 'hecho';
        }
        $pagada = (int)($r['id_estado_pago'] ?? 0) === ResumenPago::ESTADO_PAGADO_TOTAL;
        if ($pagada && in_array($estado, [Reserva::ESTADO_CONFIRMADA, Reserva::ESTADO_NO_SHOW_CON_PAGO], true)) {
            return 'checkin';
        }
        return (float)($r['saldo_pendiente'] ?? 0) > 0 || $estado === Reserva::ESTADO_PENDIENTE ? 'cobrar' : 'ver';
    }

    /** 'checkout' | 'cobrar' | 'hecho'. */
    private function accionSalida(array $r): string
    {
        if ((int)$r['id_estado_reserva'] === Reserva::ESTADO_FINALIZADA) {
            return 'hecho';
        }
        return (int)($r['id_estado_pago'] ?? 0) === ResumenPago::ESTADO_PAGADO_TOTAL ? 'checkout' : 'cobrar';
    }

    /**
     * Estado de habitación para mostrar (DESIGN.md §4): disponible, ocupada, reservada,
     * checkout (sale hoy), limpieza o mantenimiento, más una línea de detalle.
     */
    private function estadoVisual(array $h, string $hoy, array $llegaHoy): array
    {
        $huesped = trim(($h['huesped_nombre'] ?? '') . ' ' . ($h['huesped_apellido'] ?? ''));
        $id = (int)$h['id'];

        switch ((int)$h['id_estado_habitacion']) {
            case Habitacion::ESTADO_OCUPADA:
                $sale = !empty($h['huesped_salida']) && $h['huesped_salida'] <= $hoy;
                return [
                    'visual'  => $sale ? 'checkout' : 'ocupada',
                    'etiqueta'=> $sale ? 'Sale hoy' : 'Ocupada',
                    'detalle' => $huesped !== '' ? $huesped : null,
                ];
            case Habitacion::ESTADO_RETENIDA:
                return ['visual' => 'reservada', 'etiqueta' => 'Retenida', 'detalle' => $llegaHoy[$id] ?? null];
            case Habitacion::ESTADO_SUCIA:
                return ['visual' => 'limpieza', 'etiqueta' => 'Sucia', 'detalle' => isset($llegaHoy[$id]) ? 'Llega hoy' : null];
            case Habitacion::ESTADO_LIMPIANDO:
                return ['visual' => 'limpieza', 'etiqueta' => 'Limpiando', 'detalle' => isset($llegaHoy[$id]) ? 'Llega hoy' : null];
            case Habitacion::ESTADO_MANTENIMIENTO:
            case Habitacion::ESTADO_BLOQUEADA:
                return ['visual' => 'mantenimiento', 'etiqueta' => $h['estado'], 'detalle' => $h['motivo'] ?: null];
            default:
                if (isset($llegaHoy[$id])) {
                    return ['visual' => 'reservada', 'etiqueta' => 'Llega hoy', 'detalle' => $llegaHoy[$id]];
                }
                return ['visual' => 'disponible', 'etiqueta' => 'Disponible', 'detalle' => null];
        }
    }

    private function armarAlertas(array $c, int $porLimpiar, int $fueraServicio, int $suciasConLlegada): array
    {
        $alertas = [];
        if ($suciasConLlegada > 0) {
            $alertas[] = ['nivel' => 'danger', 'icono' => 'fa-broom',
                'texto' => $suciasConLlegada . ' habitación(es) sin limpiar con llegada hoy',
                'ruta' => '/dashboard/rooms'];
        }
        if ($c['salidas_vencidas'] > 0) {
            $alertas[] = ['nivel' => 'danger', 'icono' => 'fa-door-closed',
                'texto' => $c['salidas_vencidas'] . ' huésped(es) con la salida vencida sin check-out',
                'ruta' => '/dashboard/booking'];
        }
        if ($c['llegadas_atrasadas'] > 0) {
            $alertas[] = ['nivel' => 'danger', 'icono' => 'fa-user-clock',
                'texto' => $c['llegadas_atrasadas'] . ' reserva(s) confirmada(s) que no hicieron check-in',
                'ruta' => '/dashboard/booking'];
        }
        if ($c['sin_pago_proximas'] > 0) {
            $alertas[] = ['nivel' => 'warning', 'icono' => 'fa-hourglass-half',
                'texto' => $c['sin_pago_proximas'] . ' reserva(s) sin pago que llegan en los próximos ' . self::DIAS_AVISO_SIN_PAGO . ' días',
                'ruta' => '/dashboard/payments'];
        }
        if ($c['a_reembolsar'] > 0) {
            $alertas[] = ['nivel' => 'warning', 'icono' => 'fa-rotate-left',
                'texto' => $c['a_reembolsar'] . ' pago(s) a reembolsar',
                'ruta' => '/dashboard/payments'];
        }
        if ($porLimpiar > 0) {
            $alertas[] = ['nivel' => 'info', 'icono' => 'fa-broom',
                'texto' => $porLimpiar . ' habitación(es) pendiente(s) de limpieza',
                'ruta' => '/dashboard/rooms'];
        }
        if ($fueraServicio > 0) {
            $alertas[] = ['nivel' => 'info', 'icono' => 'fa-screwdriver-wrench',
                'texto' => $fueraServicio . ' habitación(es) en mantenimiento o bloqueada(s)',
                'ruta' => '/dashboard/rooms'];
        }
        return $alertas;
    }

    /** Ocupación (%) de cada noche desde hoy, sobre las habitaciones operativas. */
    private function proyeccionOcupacion(string $hoy, int $operativas): array
    {
        $hasta = date('Y-m-d', strtotime($hoy . ' +' . self::DIAS_PROYECCION . ' days'));
        $estadias = $this->reservaModel->getEstadiasEnRango($hoy, $hasta);

        $dias = [];
        for ($i = 0; $i < self::DIAS_PROYECCION; $i++) {
            $fecha = date('Y-m-d', strtotime($hoy . " +$i days"));
            $noches = 0;
            foreach ($estadias as $e) {
                if ($e['fecha_entrada'] <= $fecha && $e['fecha_salida'] > $fecha) {
                    $noches++;
                }
            }
            $dias[] = [
                'fecha'      => $fecha,
                'reservadas' => $noches,
                'pct'        => $operativas > 0 ? min(100, (int)round($noches * 100 / $operativas)) : 0,
            ];
        }
        return $dias;
    }
}
