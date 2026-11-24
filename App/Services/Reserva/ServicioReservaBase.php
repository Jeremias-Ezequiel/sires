<?php

declare(strict_types=1);

namespace App\Services\Reserva;

use App\Dominio\Tarifa;
use App\Models\Habitacion;
use App\Models\Reserva;
use App\Models\ResumenPago;
use App\Services\AuditoriaService;

/**
 * Base de los servicios de reserva: modelos, auditoría y lo que comparten el alta y la
 * extensión (resumen de pago inicial) y la cancelación (precio pactado por noche).
 * Se usan a través de la fachada App\Services\ReservaService.
 */
abstract class ServicioReservaBase
{
    protected Reserva $reservaModel;
    protected Habitacion $habitacionModel;
    protected ResumenPago $resumenModel;
    protected AuditoriaService $auditoria;

    public function __construct()
    {
        $this->auditoria       = new AuditoriaService();
        $this->reservaModel    = new Reserva();
        $this->habitacionModel = new Habitacion();
        $this->resumenModel    = new ResumenPago();
    }

    /**
     * Guarda el precio pactado por noche y crea el Resumen_Pago inicial (sin cobros)
     * de una reserva recién insertada. Debe llamarse dentro de la transacción del alta.
     */
    protected function crearResumenInicial(
        int $idReserva,
        array $habitacionRow,
        string $fechaEntrada,
        string $fechaSalida,
        int $cantHuespedes
    ): void {
        $noches = Tarifa::noches($fechaEntrada, $fechaSalida);
        $precioEfectivo = Habitacion::precioEfectivoNoche(
            (float)$habitacionRow['precio_noche_base'],
            (int)$habitacionRow['id_tipo_habitacion'],
            $cantHuespedes
        );
        $total = Tarifa::total($precioEfectivo, $noches);

        $this->reservaModel->actualizarPrecioPactado($idReserva, $precioEfectivo);

        $insertResumen = $this->reservaModel->getConnection()->prepare(
            "INSERT INTO Resumen_Pago (id_reserva, id_estado_pago, monto_total, monto_cobrado, saldo_pendiente)
             VALUES (:id_reserva, :estado, :total, 0, :saldo)"
        );
        $insertResumen->execute([
            ':id_reserva' => $idReserva,
            ':estado'     => ResumenPago::ESTADO_PENDIENTE,
            ':total'      => $total,
            ':saldo'      => $total
        ]);
    }

    /** Precio por noche acordado de una reserva (fila de findById), con fallback al precio actual. */
    protected static function precioNocheReserva(array $reserva): float
    {
        if (isset($reserva['precio_noche_pactado'])) {
            return (float)$reserva['precio_noche_pactado'];
        }
        return Habitacion::precioEfectivoNoche(
            (float)$reserva['precio_noche_base'],
            (int)$reserva['id_tipo_habitacion'],
            (int)$reserva['cantidad_huespedes']
        );
    }
}
