<?php

declare(strict_types=1);

namespace Tests\Integracion;

use App\Models\Rol;
use App\Services\EmpleadoService;

/**
 * EmpleadoService contra la base real: la operación y su auditoría van en la misma
 * transacción (si falla, no queda ni el cambio ni el registro).
 */
final class EmpleadoServiceTest extends TestIntegracion
{
    /** Datos válidos de un empleado extranjero (sin CUIL), con nacionalidad y provincia reales. */
    private function datos(array $cambios = []): array
    {
        $prov = $this->fila(
            "SELECT p.id, p.id_pais FROM Provincias p
             WHERE p.id_pais <> COALESCE((SELECT MIN(id) FROM Nacionalidades
                                         WHERE LOWER(TRIM(nombre)) IN ('argentina', 'republica argentina', 'república argentina')), 0)
             ORDER BY p.id LIMIT 1"
        );
        return $cambios + [
            'nombre' => 'Prueba', 'apellido' => 'Integracion',
            'email' => 'it.empleado.' . bin2hex(random_bytes(4)) . '@prueba.com',
            'telefono' => '', 'dni' => '', 'cuil' => '', 'direccion' => '', 'fecha_nacimiento' => '',
            'password' => 'Clave1234', 'confirm_password' => 'Clave1234',
            'id_rol' => (string)Rol::RECEPCIONISTA,
            'id_nacionalidad' => (string)$prov['id_pais'], 'id_provincia' => (string)$prov['id'], 'id_localidad' => '',
        ];
    }

    private function idPorEmail(string $email): int
    {
        return (int)$this->valor('SELECT id FROM Usuarios WHERE email = :e', [':e' => $email]);
    }

    private function auditorias(string $accion, int $id): int
    {
        return (int)$this->valor(
            "SELECT COUNT(*) FROM Auditoria WHERE accion = :a AND entidad = 'empleado' AND id_entidad = :id",
            [':a' => $accion, ':id' => $id]
        );
    }

    public function testAltaGuardaElEmpleadoConLaContraseñaHasheadaYAuditoria(): void
    {
        $datos = $this->datos();
        (new EmpleadoService())->alta($datos);

        $id = $this->idPorEmail($datos['email']);
        $this->assertGreaterThan(0, $id);
        $this->assertTrue(password_verify('Clave1234', (string)$this->valor('SELECT password FROM Usuarios WHERE id = :id', [':id' => $id])));
        $this->assertSame(1, $this->auditorias('empleado.alta', $id));
    }

    public function testBajaYReactivacionQuedanAuditadas(): void
    {
        $datos = $this->datos();
        $servicio = new EmpleadoService();
        $servicio->alta($datos);
        $id = $this->idPorEmail($datos['email']);

        $servicio->desactivar($id);
        $this->assertSame(0, (int)$this->valor('SELECT is_active FROM Usuarios WHERE id = :id', [':id' => $id]));
        $servicio->activar($id);
        $this->assertSame(1, (int)$this->valor('SELECT is_active FROM Usuarios WHERE id = :id', [':id' => $id]));

        $this->assertSame(1, $this->auditorias('empleado.desactivar', $id));
        $this->assertSame(1, $this->auditorias('empleado.activar', $id));
    }

    public function testDesactivarDosVecesFallaSinAuditar(): void
    {
        $datos = $this->datos();
        $servicio = new EmpleadoService();
        $servicio->alta($datos);
        $id = $this->idPorEmail($datos['email']);
        $servicio->desactivar($id);

        try {
            $servicio->desactivar($id);
            $this->fail('Se esperaba una excepción');
        } catch (\Exception $e) {
            $this->assertStringContainsString('ya se encuentra inactivo', $e->getMessage());
        }
        $this->assertSame(1, $this->auditorias('empleado.desactivar', $id));
    }

    public function testEditarConUnCorreoRepetidoNoCambiaNada(): void
    {
        $a = $this->datos();
        $b = $this->datos();
        $servicio = new EmpleadoService();
        $servicio->alta($a);
        $servicio->alta($b);
        $idB = $this->idPorEmail($b['email']);

        try {
            $servicio->editar($idB, ['email' => $a['email'], 'apellido' => 'Cambiado'] + $b);
            $this->fail('Se esperaba una excepción');
        } catch (\Exception $e) {
            $this->assertStringContainsString('ya está en uso', $e->getMessage());
        }
        $this->assertSame('Integracion', $this->valor('SELECT apellido FROM Usuarios WHERE id = :id', [':id' => $idB]));
        $this->assertSame(0, $this->auditorias('empleado.editar', $idB));
    }

    public function testCambioDePasswordNoGuardaLaClaveEnLaAuditoria(): void
    {
        $datos = $this->datos();
        $servicio = new EmpleadoService();
        $servicio->alta($datos);
        $id = $this->idPorEmail($datos['email']);

        $servicio->cambiarPassword($id, 'OtraClave99', 'OtraClave99');

        $this->assertTrue(password_verify('OtraClave99', (string)$this->valor('SELECT password FROM Usuarios WHERE id = :id', [':id' => $id])));
        $fila = $this->fila(
            "SELECT antes, despues FROM Auditoria WHERE accion = 'empleado.cambio_password' AND id_entidad = :id",
            [':id' => $id]
        );
        $this->assertNull($fila['antes']);
        $this->assertNull($fila['despues']);
    }
}
