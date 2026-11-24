<?php

declare(strict_types=1);

namespace Tests\Unidad;

use App\Dominio\Comprobante;
use App\Dominio\ConfiguracionVersionada;
use PHPUnit\Framework\TestCase;

final class ComprobanteTest extends TestCase
{
    public function testNumeroFormateado(): void
    {
        $this->assertSame('0001-00000012', Comprobante::numeroFormateado(1, 12));
        $this->assertSame('0023-12345678', Comprobante::numeroFormateado(23, 12345678));
    }

    public function testNumeracionCorrelativa(): void
    {
        $this->assertSame(1, Comprobante::siguienteNumero(null));
        $this->assertSame(13, Comprobante::siguienteNumero(12));
    }

    public function testTipoSegunElSignoDeLaTransaccion(): void
    {
        $this->assertSame(Comprobante::PAGO, Comprobante::tipoDeTransaccion(1500));
        $this->assertSame(Comprobante::REEMBOLSO, Comprobante::tipoDeTransaccion(-1500));
        $this->expectException(\InvalidArgumentException::class);
        Comprobante::tipoDeTransaccion(0);
    }

    public function testNombreDeArchivo(): void
    {
        $this->assertSame('recibo-de-pago-0001-00000012.pdf', Comprobante::nombreArchivo(Comprobante::PAGO, 1, 12));
        $this->assertSame('resumen-de-la-reserva-0002-00000003.pdf', Comprobante::nombreArchivo(Comprobante::RESUMEN, 2, 3));
    }

    public function testConfiguracionTipoTexto(): void
    {
        $this->assertSame('Hotel Sol S.A.', ConfiguracionVersionada::convertir('Hotel Sol S.A.', 'texto'));
        $this->assertSame('', ConfiguracionVersionada::convertir('', 'texto'));
    }
}
