<?php

declare(strict_types=1);

namespace Tests\Unidad;

use App\Dominio\ConfiguracionVersionada;
use PHPUnit\Framework\TestCase;

final class ConfiguracionVersionadaTest extends TestCase
{
    /** Historial de sena_minima_pct: 30 % desde el inicio, 50 % desde el 2026-12-01. */
    private const SENA = [
        ['valor' => '50', 'tipo' => 'int', 'vigente_desde' => '2026-12-01 00:00:00'],
        ['valor' => '30', 'tipo' => 'int', 'vigente_desde' => '2026-01-01 00:00:00'],
    ];

    public function testReservaHechaAntesDelCambioUsaLaPoliticaVieja(): void
    {
        $this->assertSame(30, ConfiguracionVersionada::valorVigente(self::SENA, '2026-11-20 15:00:00', 10));
        $this->assertSame(30, ConfiguracionVersionada::valorVigente(self::SENA, '2026-11-30 23:59:59', 10));
    }

    public function testReservaHechaDesdeElCambioUsaLaNueva(): void
    {
        $this->assertSame(50, ConfiguracionVersionada::valorVigente(self::SENA, '2026-12-01 00:00:00', 10));
        $this->assertSame(50, ConfiguracionVersionada::valorVigente(self::SENA, '2027-03-01 10:00:00', 10));
    }

    public function testSinFilaVigenteUsaElValorPorDefecto(): void
    {
        $this->assertSame(10, ConfiguracionVersionada::valorVigente(self::SENA, '2025-06-01 00:00:00', 10));
        $this->assertSame('11:00', ConfiguracionVersionada::valorVigente([], '2026-11-06 10:00:00', '11:00'));
    }

    public function testFechaSinHoraValeComoMedianoche(): void
    {
        $this->assertSame(50, ConfiguracionVersionada::valorVigente(self::SENA, '2026-12-01', 10));
        $filas = [['valor' => '1', 'tipo' => 'int', 'vigente_desde' => '2026-12-01 08:00:00']];
        $this->assertNull(ConfiguracionVersionada::filaVigente($filas, '2026-12-01'));
    }

    public function testElOrdenDeLasFilasNoImporta(): void
    {
        $this->assertSame(50, ConfiguracionVersionada::valorVigente(array_reverse(self::SENA), '2026-12-15 00:00:00', 10));
    }

    public function testConversionPorTipo(): void
    {
        $this->assertSame(48, ConfiguracionVersionada::convertir('48', 'int'));
        $this->assertSame(0.5, ConfiguracionVersionada::convertir('0.5', 'decimal'));
        $this->assertSame('11:00', ConfiguracionVersionada::convertir('11:00:00', 'time'));
        $this->assertSame([3 => [1 => 25]], ConfiguracionVersionada::convertir('{"3":{"1":25}}', 'json'));
        $this->assertSame('texto', ConfiguracionVersionada::convertir('texto', 'string'));
    }

    public function testEnteroInvalido(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ConfiguracionVersionada::convertir('4.5', 'int');
    }

    public function testHoraInvalida(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ConfiguracionVersionada::convertir('25:00', 'time');
    }

    public function testJsonQueNoEsObjeto(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ConfiguracionVersionada::convertir('7', 'json');
    }

    public function testJsonRoto(): void
    {
        $this->expectException(\JsonException::class);
        ConfiguracionVersionada::convertir('{roto', 'json');
    }
}
