<?php

declare(strict_types=1);

namespace Tests\Unidad;

use App\Dominio\Planning;
use App\Models\EstadoReserva;
use PHPUnit\Framework\TestCase;

final class PlanningTest extends TestCase
{
    public function testDiasPermitidos(): void
    {
        $this->assertSame(14, Planning::normalizarDias('14'));
        $this->assertSame(30, Planning::normalizarDias('30'));
        $this->assertSame(14, Planning::normalizarDias('7'));
        $this->assertSame(14, Planning::normalizarDias(null));
        $this->assertSame(14, Planning::normalizarDias('30abc'));
    }

    public function testDesdeInvalidoUsaHoy(): void
    {
        $this->assertSame('2026-11-20', Planning::normalizarDesde('2026-11-20', '2026-11-06'));
        $this->assertSame('2026-11-06', Planning::normalizarDesde('2026-02-30', '2026-11-06'));
        $this->assertSame('2026-11-06', Planning::normalizarDesde('20/11/2026', '2026-11-06'));
        $this->assertSame('2026-11-06', Planning::normalizarDesde(['x'], '2026-11-06'));
    }

    public function testDesplazar(): void
    {
        $this->assertSame('2026-11-20', Planning::desplazar('2026-11-06', 14));
        $this->assertSame('2026-10-23', Planning::desplazar('2026-11-06', -14));
        $this->assertSame('2027-01-05', Planning::desplazar('2026-12-06', 30));
    }

    public function testColumnasDelRango(): void
    {
        $dias = Planning::dias('2026-11-06', 14, '2026-11-07');
        $this->assertCount(14, $dias);
        $this->assertSame(['fecha' => '2026-11-06', 'numero' => 6, 'semana' => 'vie', 'hoy' => false, 'finde' => false, 'pasado' => true, 'primeroDeMes' => false], $dias[0]);
        $this->assertTrue($dias[1]['hoy']);
        $this->assertTrue($dias[1]['finde']); // sábado
        $this->assertFalse($dias[1]['pasado']);
        $this->assertSame('2026-11-19', $dias[13]['fecha']);
    }

    public function testPrimeroDeMes(): void
    {
        $dias = Planning::dias('2026-11-29', 3, '2026-11-01');
        $this->assertSame([false, false, true], array_column($dias, 'primeroDeMes'));
    }

    public function testBarraDentroDelRango(): void
    {
        $this->assertSame(
            ['inicio' => 2, 'span' => 3, 'sigueAntes' => false, 'sigueDespues' => false],
            Planning::barra('2026-11-08', '2026-11-11', '2026-11-06', 14)
        );
    }

    public function testBarraQueEmpiezaAntesYTerminaDespues(): void
    {
        $this->assertSame(
            ['inicio' => 0, 'span' => 14, 'sigueAntes' => true, 'sigueDespues' => true],
            Planning::barra('2026-11-01', '2026-11-30', '2026-11-06', 14)
        );
    }

    public function testBarraQueSaleElPrimerDiaDelRangoNoSeDibuja(): void
    {
        // Salida el 06: la última noche es la del 05, fuera del rango.
        $this->assertNull(Planning::barra('2026-11-03', '2026-11-06', '2026-11-06', 14));
    }

    public function testBarraQueEntraElDiaSiguienteAlRangoNoSeDibuja(): void
    {
        $this->assertNull(Planning::barra('2026-11-20', '2026-11-22', '2026-11-06', 14));
        $this->assertSame(
            ['inicio' => 13, 'span' => 1, 'sigueAntes' => false, 'sigueDespues' => true],
            Planning::barra('2026-11-19', '2026-11-22', '2026-11-06', 14)
        );
    }

    public function testBarraConFechasInvertidasNoSeDibuja(): void
    {
        $this->assertNull(Planning::barra('2026-11-10', '2026-11-10', '2026-11-06', 14));
        $this->assertNull(Planning::barra('2026-11-12', '2026-11-10', '2026-11-06', 14));
    }

    public function testEstilos(): void
    {
        $this->assertSame('pendiente', Planning::estilo(EstadoReserva::PENDIENTE, '2026-11-10', '2026-11-06'));
        $this->assertSame('confirmada', Planning::estilo(EstadoReserva::CONFIRMADA, '2026-11-10', '2026-11-06'));
        $this->assertSame('en-casa', Planning::estilo(EstadoReserva::EN_CASA, '2026-11-10', '2026-11-06'));
        $this->assertSame('sale-hoy', Planning::estilo(EstadoReserva::EN_CASA, '2026-11-06', '2026-11-06'));
        $this->assertSame('no-show', Planning::estilo(EstadoReserva::NO_SHOW_CON_PAGO, '2026-11-10', '2026-11-06'));
        $this->assertSame('finalizada', Planning::estilo(EstadoReserva::FINALIZADA_CHECKOUT, '2026-11-05', '2026-11-06'));
        foreach (Planning::ESTADOS_VISIBLES as $estado) {
            $this->assertArrayHasKey(Planning::estilo($estado, '2026-11-10', '2026-11-06'), Planning::ESTILOS);
        }
    }

    public function testCheckoutAnticipadoCortaLaBarraEnLaSalidaReal(): void
    {
        $this->assertSame('2026-11-08', Planning::salidaDibujada('2026-11-10', '2026-11-08'));
        // Sin fecha real (reservas viejas o no finalizadas): la pactada
        $this->assertSame('2026-11-10', Planning::salidaDibujada('2026-11-10', null));
        $this->assertSame('2026-11-10', Planning::salidaDibujada('2026-11-10', ''));
        // Check-out el día pactado o después: no se alarga la barra
        $this->assertSame('2026-11-10', Planning::salidaDibujada('2026-11-10', '2026-11-10'));
        $this->assertSame('2026-11-10', Planning::salidaDibujada('2026-11-10', '2026-11-11'));
    }

    public function testSeRetiroElMismoDiaDeEntradaNoOcupaNoches(): void
    {
        $salida = Planning::salidaDibujada('2026-11-10', '2026-11-07');
        $this->assertNull(Planning::barra('2026-11-07', $salida, '2026-11-01', 14));
    }
}
