<?php

declare(strict_types=1);

namespace Tests\Unidad;

use App\Dominio\ReglasCliente;
use PHPUnit\Framework\TestCase;

final class ReglasClienteTest extends TestCase
{
    private static function datos(array $cambios = []): array
    {
        return $cambios + [
            'nombre'          => 'Ana',
            'apellido'        => 'Gómez',
            'dni_pasaporte'   => '35123456',
            'telefono'        => '',
            'mail'            => 'Ana@Correo.com',
            'observaciones'   => '',
            'id_nacionalidad' => '1',
            'id_provincia'    => '3',
            'id_localidad'    => '7',
        ];
    }

    public function testElTelefonoYLasObservacionesSonOpcionales(): void
    {
        $d = ReglasCliente::normalizar(self::datos());

        $this->assertNull($d['telefono']);
        $this->assertNull($d['observaciones']);
        $this->assertSame('ana@correo.com', $d['email']);
        $this->assertSame(7, $d['id_localidad']);
    }

    public function testAceptaPasaporte(): void
    {
        $this->assertSame('AB123456', ReglasCliente::normalizar(self::datos(['dni_pasaporte' => 'AB123456']))['dni_pasaporte']);
    }

    public function testRechazaUnDocumentoInvalido(): void
    {
        $this->expectExceptionMessage('DNI o Pasaporte no es válido');
        ReglasCliente::normalizar(self::datos(['dni_pasaporte' => '12']));
    }

    public function testLaProvinciaEsObligatoria(): void
    {
        $this->expectExceptionMessage('Debe seleccionar una provincia');
        ReglasCliente::normalizar(self::datos(['id_provincia' => '0']));
    }
}
