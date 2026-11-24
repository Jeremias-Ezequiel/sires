<?php

declare(strict_types=1);

namespace Tests\Unidad;

use App\Dominio\ReglasEmpleado;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReglasEmpleadoTest extends TestCase
{
    private const HOY = '2026-11-07';

    /** Empleado argentino válido (CUIL 20-35123456-? derivado del DNI). */
    private static function datos(array $cambios = []): array
    {
        return $cambios + [
            'nombre'           => '  Laura ',
            'apellido'         => 'Pérez',
            'email'            => 'Laura.Perez@Hotel.com',
            'telefono'         => '+54 9 11 1234-5678',
            'dni'              => '35.123.456',
            'cuil'             => self::cuil('35123456'),
            'direccion'        => '',
            'fecha_nacimiento' => '1990-05-10',
            'password'         => 'Clave1234',
            'confirm_password' => 'Clave1234',
            'id_rol'           => '2',
            'id_provincia'     => '3',
            'id_localidad'     => '',
            'id_nacionalidad'  => '1',
        ];
    }

    private static function cuil(string $dni): string
    {
        $base = '20' . $dni;
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        foreach (str_split($base) as $i => $d) {
            $suma += (int)$d * $pesos[$i];
        }
        $dv = 11 - ($suma % 11);
        $dv = $dv === 11 ? 0 : ($dv === 10 ? 9 : $dv);
        return "20-{$dni}-{$dv}";
    }

    public function testNormalizaLosDatosDeUnArgentino(): void
    {
        $d = ReglasEmpleado::normalizar(self::datos(), true, true, self::HOY);

        $this->assertSame('Laura', $d['nombre']);
        $this->assertSame('laura.perez@hotel.com', $d['email']);
        $this->assertSame('+5491112345678', $d['telefono']);
        $this->assertSame('35123456', $d['dni']);
        $this->assertNull($d['direccion']);
        $this->assertNull($d['id_localidad']);
        $this->assertSame(2, $d['id_rol']);
        $this->assertSame(1, $d['id_nacionalidad']);
    }

    public function testElCuilEsObligatorioParaArgentinos(): void
    {
        $this->expectExceptionMessage('CUIL es obligatorio');
        ReglasEmpleado::normalizar(self::datos(['cuil' => '']), true, false, self::HOY);
    }

    public function testElCuilEsOpcionalParaExtranjeros(): void
    {
        $d = ReglasEmpleado::normalizar(self::datos(['cuil' => '', 'dni' => '']), false, false, self::HOY);
        $this->assertSame('', $d['cuil']);
    }

    public function testElCuilTieneQueDerivarDelDni(): void
    {
        $this->expectExceptionMessage('no coincide con el DNI');
        ReglasEmpleado::normalizar(self::datos(['cuil' => self::cuil('30111222')]), true, false, self::HOY);
    }

    public function testLaContraseñaSoloSeExigeEnElAlta(): void
    {
        $d = ReglasEmpleado::normalizar(self::datos(['password' => '', 'confirm_password' => '']), true, false, self::HOY);
        $this->assertSame('', $d['password']);

        $this->expectExceptionMessage('La contraseña es obligatoria.');
        ReglasEmpleado::normalizar(self::datos(['password' => '', 'confirm_password' => '']), true, true, self::HOY);
    }

    public function testLaEdadSeCalculaALaFechaDeReferencia(): void
    {
        // Cumple 18 el 2026-11-08: el día anterior todavía es menor
        $this->expectExceptionMessage('mayor de edad');
        ReglasEmpleado::normalizar(self::datos(['fecha_nacimiento' => '2008-11-08']), true, false, self::HOY);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function datosInvalidos(): array
    {
        return [
            'nombre con números' => [['nombre' => 'Juan123'], 'solo puede contener letras y espacios'],
            'sin rol'            => [['id_rol' => '0'], 'Debe seleccionar un rol'],
            'sin nacionalidad'   => [['id_nacionalidad' => ''], 'Debe seleccionar una nacionalidad'],
            'sin provincia'      => [['id_provincia' => ''], 'Debe seleccionar una provincia'],
            'correo inválido'    => [['email' => 'no-es-correo'], 'correo electrónico no es válido'],
            'teléfono corto'     => [['telefono' => '123'], 'E.164'],
        ];
    }

    #[DataProvider('datosInvalidos')]
    public function testRechazaDatosInvalidos(array $cambios, string $mensaje): void
    {
        $this->expectExceptionMessage($mensaje);
        ReglasEmpleado::normalizar(self::datos($cambios), true, false, self::HOY);
    }

    public function testContraseñasDistintas(): void
    {
        $this->expectExceptionMessage('no coinciden');
        ReglasEmpleado::validarPassword('Clave1234', 'Clave1235');
    }

    public function testContraseñaDebil(): void
    {
        $this->expectExceptionMessage('mayúscula');
        ReglasEmpleado::validarPassword('clave12345', 'clave12345');
    }
}
