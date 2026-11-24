<?php

declare(strict_types=1);

namespace Tests\Unidad;

use App\Dominio\Tarifa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TarifaTest extends TestCase
{
    /** Misma forma que la clave descuento_ocupacion (JSON decodificado). */
    private const TABLA = [
        3 => [1 => 25, 2 => 10, 3 => 0],
        2 => [1 => 15, 2 => 0],
    ];

    public function testNochesEntreFechas(): void
    {
        $this->assertSame(3, Tarifa::noches('2026-11-10', '2026-11-13'));
        $this->assertSame(1, Tarifa::noches('2026-12-31', '2027-01-01'));
        $this->assertSame(0, Tarifa::noches('2026-11-10', '2026-11-10'));
    }

    public function testNochesCruzandoCambioDeMes(): void
    {
        $this->assertSame(29, Tarifa::noches('2028-02-01', '2028-03-01')); // año bisiesto
    }

    /** @return iterable<string, array{int, int, float}> */
    public static function casosDescuento(): iterable
    {
        yield 'triple con 1 huésped' => [3, 1, 25.0];
        yield 'triple con 2 huéspedes' => [3, 2, 10.0];
        yield 'triple completa' => [3, 3, 0.0];
        yield 'doble con 1 huésped' => [2, 1, 15.0];
        yield 'suite (capacidad sin tabla)' => [4, 1, 0.0];
        yield 'huéspedes fuera de tabla' => [2, 5, 0.0];
    }

    #[DataProvider('casosDescuento')]
    public function testDescuentoOcupacion(int $capacidad, int $huespedes, float $esperado): void
    {
        $this->assertSame($esperado, Tarifa::descuentoOcupacion(self::TABLA, $capacidad, $huespedes));
    }

    public function testDescuentoConClavesDeTexto(): void
    {
        // Un JSON con claves "3" decodifica a enteros, pero se tolera también texto.
        $tabla = ['3' => ['1' => 20]];
        $this->assertSame(20.0, Tarifa::descuentoOcupacion($tabla, 3, 1));
    }

    public function testDescuentoConTablaVacia(): void
    {
        $this->assertSame(0.0, Tarifa::descuentoOcupacion([], 3, 1));
    }

    public function testPrecioEfectivoAplicaDescuento(): void
    {
        $this->assertEqualsWithDelta(75000.0, Tarifa::precioEfectivoNoche(100000, 3, 1, self::TABLA), 0.001);
        $this->assertEqualsWithDelta(85000.0, Tarifa::precioEfectivoNoche(100000, 2, 1, self::TABLA), 0.001);
        $this->assertEqualsWithDelta(100000.0, Tarifa::precioEfectivoNoche(100000, 4, 1, self::TABLA), 0.001);
    }

    public function testTotal(): void
    {
        $this->assertEqualsWithDelta(225000.0, Tarifa::total(75000, 3), 0.001);
        $this->assertSame(0.0, Tarifa::total(75000, 0));
    }
}
