<?php

declare(strict_types=1);

namespace Tests\Unidad;

use App\Dominio\ReglasPago;
use App\Models\EstadoPago;
use PHPUnit\Framework\TestCase;

final class ReglasPagoTest extends TestCase
{
    public function testSenaMinimaRedondeaACentavos(): void
    {
        $this->assertSame(30000.0, ReglasPago::senaMinima(100000, 30));
        $this->assertSame(33.33, ReglasPago::senaMinima(111.11, 30));
        $this->assertSame(0.0, ReglasPago::senaMinima(100000, 0));
    }

    public function testAlcanzaSena(): void
    {
        $this->assertTrue(ReglasPago::alcanzaSena(30000, 100000, 30));
        $this->assertTrue(ReglasPago::alcanzaSena(50000, 100000, 30));
        $this->assertFalse(ReglasPago::alcanzaSena(29999.99, 100000, 30));
        $this->assertTrue(ReglasPago::alcanzaSena(33.33, 111.11, 30), 'compara contra el monto redondeado');
    }

    public function testEstadoTrasPago(): void
    {
        $this->assertSame(EstadoPago::PAGADO_TOTAL, ReglasPago::estadoTrasPago(0, 1000));
        $this->assertSame(EstadoPago::PAGADO_TOTAL, ReglasPago::estadoTrasPago(-0.01, 1000));
        $this->assertSame(EstadoPago::PAGO_PARCIAL, ReglasPago::estadoTrasPago(500, 500));
        $this->assertSame(EstadoPago::PENDIENTE, ReglasPago::estadoTrasPago(1000, 0));
    }

    public function testEstadoTrasRecalculo(): void
    {
        $this->assertSame(EstadoPago::PAGADO_TOTAL, ReglasPago::estadoTrasRecalculo(0, 1000));
        $this->assertSame(EstadoPago::PAGO_PARCIAL, ReglasPago::estadoTrasRecalculo(500, 500));
        $this->assertSame(EstadoPago::PENDIENTE, ReglasPago::estadoTrasRecalculo(1000, 0));
        $this->assertSame(EstadoPago::PENDIENTE, ReglasPago::estadoTrasRecalculo(0, 0), 'total 0 sin cobros no es pagado');
    }

    public function testRecalculoConservaReembolsadoYRetenido(): void
    {
        $this->assertSame(EstadoPago::REEMBOLSADO, ReglasPago::estadoTrasRecalculo(0, 1000, EstadoPago::REEMBOLSADO));
        $this->assertSame(EstadoPago::RETENIDO, ReglasPago::estadoTrasRecalculo(500, 500, EstadoPago::RETENIDO));
        $this->assertSame(EstadoPago::PAGO_PARCIAL, ReglasPago::estadoTrasRecalculo(500, 500, EstadoPago::PAGADO_TOTAL));
    }

    public function testRetencionSinCobrosNoModificaNada(): void
    {
        $this->assertNull(ReglasPago::retencion(0, 5000));
        $this->assertNull(ReglasPago::retencion(-10, 5000));
    }

    public function testRetencionMenorALoCobradoQuedaAReembolsar(): void
    {
        $this->assertSame(
            ['retenido' => 3000.0, 'estado' => EstadoPago::A_REEMBOLSAB],
            ReglasPago::retencion(10000, 3000)
        );
    }

    public function testRetencionConTopeEnLoCobrado(): void
    {
        $this->assertSame(
            ['retenido' => 10000.0, 'estado' => EstadoPago::RETENIDO],
            ReglasPago::retencion(10000, 25000)
        );
        $this->assertSame(
            ['retenido' => 10000.0, 'estado' => EstadoPago::RETENIDO],
            ReglasPago::retencion(10000, 10000)
        );
    }

    public function testRetencionCeroOMenorDevuelveTodo(): void
    {
        $this->assertSame(
            ['retenido' => 0.0, 'estado' => EstadoPago::A_REEMBOLSAB],
            ReglasPago::retencion(10000, -50)
        );
    }

    public function testEstadoTrasReembolso(): void
    {
        $this->assertSame(EstadoPago::RETENIDO, ReglasPago::estadoTrasReembolso(1000));
        $this->assertSame(EstadoPago::REEMBOLSADO, ReglasPago::estadoTrasReembolso(0));
    }

    public function testLateFee(): void
    {
        $this->assertSame(50000.0, ReglasPago::lateFee(100000, 0.5));
        $this->assertSame(33.34, ReglasPago::lateFee(66.675, 0.5));
        $this->assertSame(0.0, ReglasPago::lateFee(100000, 0));
    }
}
