<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Reserva\AltaReservaService;
use App\Services\Reserva\BajaReservaService;
use App\Services\Reserva\CambioHabitacionService;
use App\Services\Reserva\EstadiaService;

/**
 * Punto de entrada de las operaciones sobre reservas (controllers, cron y tests).
 * Cada grupo vive en su servicio de App\Services\Reserva:
 *   - AltaReservaService: crear, editar, confirmar.
 *   - EstadiaService: check-in, check-out, checkout anticipado, extensión.
 *   - CambioHabitacionService: traslado y upgrade.
 *   - BajaReservaService: cancelación, No-Show y liberación automática del No-Show con pago.
 */
class ReservaService
{
    private ?AltaReservaService $alta = null;
    private ?EstadiaService $estadia = null;
    private ?CambioHabitacionService $cambio = null;
    private ?BajaReservaService $baja = null;

    // --- Alta ---

    public function crear(array $datos): array
    {
        return $this->alta()->crear($datos);
    }

    public function editar(int $idReserva, array $datos): array
    {
        return $this->alta()->editar($idReserva, $datos);
    }

    public function confirmar(int $idReserva): array
    {
        return $this->alta()->confirmar($idReserva);
    }

    // --- Estadía ---

    public function checkIn(int $idReserva): array
    {
        return $this->estadia()->checkIn($idReserva);
    }

    public function checkOut(int $idReserva): array
    {
        return $this->estadia()->checkOut($idReserva);
    }

    public function checkoutAnticipado(int $idReserva): array
    {
        return $this->estadia()->checkoutAnticipado($idReserva);
    }

    public function extenderEstadia(int $idReserva, string $nuevaFechaSalida): array
    {
        return $this->estadia()->extenderEstadia($idReserva, $nuevaFechaSalida);
    }

    // --- Cambio de habitación ---

    public function moverHabitacion(int $idReserva, int $idNuevaHabitacion): array
    {
        return $this->cambio()->moverHabitacion($idReserva, $idNuevaHabitacion);
    }

    public function getOpcionesUpgrade(int $idReserva): array
    {
        return $this->cambio()->getOpcionesUpgrade($idReserva);
    }

    public function upgradeHabitacion(int $idReserva, int $idNuevaHabitacion): array
    {
        return $this->cambio()->upgradeHabitacion($idReserva, $idNuevaHabitacion);
    }

    // --- Baja ---

    public function cancelar(int $idReserva): array
    {
        return $this->baja()->cancelar($idReserva);
    }

    public function marcarNoShow(int $idReserva): array
    {
        return $this->baja()->marcarNoShow($idReserva);
    }

    /** Respaldo del EVENT liberacion_no_show (cron/liberar_no_show.php). */
    public function liberarNoShowConPago(): array
    {
        return $this->baja()->liberarNoShowConPago();
    }

    // Cada servicio se crea al usarlo: una acción toca un solo grupo.
    private function alta(): AltaReservaService
    {
        return $this->alta ??= new AltaReservaService();
    }

    private function estadia(): EstadiaService
    {
        return $this->estadia ??= new EstadiaService();
    }

    private function cambio(): CambioHabitacionService
    {
        return $this->cambio ??= new CambioHabitacionService();
    }

    private function baja(): BajaReservaService
    {
        return $this->baja ??= new BajaReservaService();
    }
}
