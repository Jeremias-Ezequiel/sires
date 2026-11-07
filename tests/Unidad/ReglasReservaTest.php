<?php

declare(strict_types=1);

namespace Tests\Unidad;

use App\Dominio\PoliticaReserva;
use App\Dominio\ReglasReserva;
use PHPUnit\Framework\TestCase;

final class ReglasReservaTest extends TestCase
{
    private static function politica(int $horasGratis = 72, int $penalidad = 1, int $noShow = 1): PoliticaReserva
    {
        return new PoliticaReserva(30, $horasGratis, $penalidad, $noShow, 0.5);
    }

    public function testHorasAnticipacionHastaMedianocheDeLaEntrada(): void
    {
        $ahora = (int)strtotime('2026-11-07 12:00:00');
        $this->assertSame(60.0, ReglasReserva::horasAnticipacion('2026-11-10', $ahora));
        $this->assertSame(-12.0, ReglasReserva::horasAnticipacion('2026-11-07', $ahora));
    }

    public function testCancelacionConAnticipacionEsSinCargo(): void
    {
        $this->assertSame(0.0, ReglasReserva::retencionCancelacion(50000, 3, 72.0, self::politica()));
        $this->assertSame(0.0, ReglasReserva::retencionCancelacion(50000, 3, 200.0, self::politica()));
    }

    public function testCancelacionTardiaRetieneNochesDePenalidad(): void
    {
        $this->assertSame(50000.0, ReglasReserva::retencionCancelacion(50000, 3, 71.9, self::politica()));
        $this->assertSame(100000.0, ReglasReserva::retencionCancelacion(50000, 3, 10, self::politica(penalidad: 2)));
    }

    public function testPenalidadNoSuperaLasNochesDeLaReserva(): void
    {
        $this->assertSame(50000.0, ReglasReserva::retencionCancelacion(50000, 1, 10, self::politica(penalidad: 3)));
    }

    public function testCancelacionConEntradaVencida(): void
    {
        $this->assertSame(50000.0, ReglasReserva::retencionCancelacion(50000, 2, -5, self::politica()));
    }

    public function testPoliticaSinPlazoGratisSiempreCobra(): void
    {
        $this->assertSame(0.0, ReglasReserva::retencionCancelacion(50000, 2, 0, self::politica(horasGratis: 0)));
        $this->assertSame(50000.0, ReglasReserva::retencionCancelacion(50000, 2, -0.1, self::politica(horasGratis: 0)));
    }

    public function testFechaLiberacionNoShow(): void
    {
        $this->assertSame('2026-11-11', ReglasReserva::fechaLiberacionNoShow('2026-11-10', '2026-11-15', 1));
        $this->assertSame('2026-12-02', ReglasReserva::fechaLiberacionNoShow('2026-11-30', '2026-12-05', 2));
    }

    public function testFechaLiberacionNoShowNoPasaLaSalida(): void
    {
        $this->assertSame('2026-11-12', ReglasReserva::fechaLiberacionNoShow('2026-11-10', '2026-11-12', 5));
    }

    public function testRetencionNoShow(): void
    {
        $this->assertSame(100000.0, ReglasReserva::retencionNoShow(50000, 2));
        $this->assertSame(0.0, ReglasReserva::retencionNoShow(50000, 0));
    }

    public function testNoShowAntesDeLaEntrada(): void
    {
        $this->assertSame(
            'No se puede marcar como No-Show antes de la fecha de entrada.',
            ReglasReserva::errorNoShow('2026-11-11', '2026-11-10', '23:30', '23:00')
        );
    }

    public function testNoShowElDiaDeEntradaAntesDeLaHora(): void
    {
        $this->assertSame(
            'El No-Show solo puede marcarse después de las 23:00 del día de entrada.',
            ReglasReserva::errorNoShow('2026-11-10', '2026-11-10', '22:59', '23:00')
        );
    }

    public function testNoShowPermitido(): void
    {
        $this->assertNull(ReglasReserva::errorNoShow('2026-11-10', '2026-11-10', '23:00', '23:00'));
        $this->assertNull(ReglasReserva::errorNoShow('2026-11-09', '2026-11-10', '08:00', '23:00'));
    }

    public function testAgregarObservacionVaEnSuPropiaLinea(): void
    {
        $this->assertSame('- Upgrade', ReglasReserva::agregarObservacion(null, 'Upgrade'));
        $this->assertSame('- Upgrade', ReglasReserva::agregarObservacion('', 'Upgrade'));
        $this->assertSame("Cliente VIP\n- Upgrade", ReglasReserva::agregarObservacion("Cliente VIP\n", 'Upgrade'));
        $this->assertSame("- A\n- B", ReglasReserva::agregarObservacion('- A', 'B'));
    }

    public function testObservacionesLegiblesNormalizaElFormatoViejo(): void
    {
        $this->assertSame('', ReglasReserva::observacionesLegibles(null));
        $this->assertSame(
            "Cliente VIP\n- Recálculo manual\n- Late Checkout Fee Aplicado",
            ReglasReserva::observacionesLegibles('Cliente VIP | Recálculo manual | Late Checkout Fee Aplicado')
        );
        // Sin observación inicial, el CONCAT viejo dejaba " | " al principio
        $this->assertSame('- Cancelada automática', ReglasReserva::observacionesLegibles(' | Cancelada automática'));
        // Texto escapado al guardar en versiones anteriores
        $this->assertSame("Pago & seña de O'Brien", ReglasReserva::observacionesLegibles('Pago &amp; seña de O&#039;Brien'));
        $this->assertSame("- A\n- B", ReglasReserva::observacionesLegibles("- A\n- B"));
    }

    public function testTipoCheckoutSegunLaFechaDeSalida(): void
    {
        $enCasa = \App\Models\EstadoReserva::EN_CASA;
        $this->assertSame(ReglasReserva::CHECKOUT_ANTICIPADO, ReglasReserva::tipoCheckout($enCasa, '2026-11-10', '2026-11-07'));
        $this->assertSame(ReglasReserva::CHECKOUT, ReglasReserva::tipoCheckout($enCasa, '2026-11-07', '2026-11-07'));
        // Se pasó de la fecha de salida: sigue siendo check-out normal
        $this->assertSame(ReglasReserva::CHECKOUT, ReglasReserva::tipoCheckout($enCasa, '2026-11-05', '2026-11-07'));
    }

    public function testSinCheckoutSiElHuespedNoEstaEnCasa(): void
    {
        foreach ([\App\Models\EstadoReserva::CONFIRMADA, \App\Models\EstadoReserva::PENDIENTE, \App\Models\EstadoReserva::FINALIZADA_CHECKOUT] as $estado) {
            $this->assertNull(ReglasReserva::tipoCheckout($estado, '2026-11-07', '2026-11-07'));
        }
    }
}
