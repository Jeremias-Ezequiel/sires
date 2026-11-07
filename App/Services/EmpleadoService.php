<?php

declare(strict_types=1);

namespace App\Services;

use App\Dominio\ReglasEmpleado;
use App\Models\Usuario;
use Exception;
use PDO;

/**
 * Alta, edición, baja lógica, reactivación y cambio de contraseña de empleados.
 * Cada operación corre en una transacción con su registro de auditoría.
 */
final class EmpleadoService
{
    private Usuario $usuarios;
    private PDO $db;
    private AuditoriaService $auditoria;
    private UbicacionService $ubicacion;

    public function __construct()
    {
        $this->usuarios  = new Usuario();
        $this->db        = $this->usuarios->getConnection();
        $this->auditoria = new AuditoriaService();
        $this->ubicacion = new UbicacionService();
    }

    /** @param array<string, mixed> $datos Datos del formulario de alta. */
    public function alta(array $datos): array
    {
        $d = $this->validar($datos, true);

        return $this->enTransaccion(function () use ($d): array {
            $usuario = $this->armar($d);
            $usuario->setPassword($d['password']);
            if (!$this->usuarios->save($usuario)) {
                throw new Exception("No se pudo guardar el registro en el sistema. Por favor, revise la información ingresada o comuníquese con un administrador.");
            }
            $this->auditoria->registrar('empleado.alta', 'empleado', (int)$this->db->lastInsertId(), 'Alta de empleado');
            return ['success' => true, 'message' => 'Empleado registrado exitosamente.'];
        });
    }

    /** @param array<string, mixed> $datos Datos del formulario de edición. */
    public function editar(int $id, array $datos): array
    {
        $d = $this->validar($datos, false);

        return $this->enTransaccion(function () use ($id, $d): array {
            $antes = $this->auditoria->foto('empleado', $id);
            $usuario = $this->armar($d);
            $usuario->setId($id);
            if (!$this->usuarios->update($usuario)) {
                throw new Exception("No se pudo actualizar el empleado. Verifique los datos ingresados.");
            }
            $this->auditoria->registrar('empleado.editar', 'empleado', $id, 'Edición de empleado', $antes);
            return ['success' => true, 'message' => 'Empleado actualizado exitosamente.'];
        });
    }

    public function desactivar(int $id): array
    {
        return $this->enTransaccion(function () use ($id): array {
            $antes = $this->auditoria->foto('empleado', $id);
            // El modelo devuelve false si no existe o ya estaba inactivo (rowCount)
            if (!$this->usuarios->deactivate($id)) {
                throw new Exception("El empleado no existe o ya se encuentra inactivo en el sistema.");
            }
            $this->auditoria->registrar('empleado.desactivar', 'empleado', $id, 'Baja de acceso', $antes);
            return ['success' => true, 'message' => 'Empleado desactivado exitosamente.'];
        });
    }

    public function activar(int $id): array
    {
        return $this->enTransaccion(function () use ($id): array {
            $antes = $this->auditoria->foto('empleado', $id);
            if (!$this->usuarios->activate($id)) {
                throw new Exception("El empleado no existe o ya se encuentra activo en el sistema.");
            }
            $this->auditoria->registrar('empleado.activar', 'empleado', $id, 'Reactivación de acceso', $antes);
            return ['success' => true, 'message' => 'Empleado reactivado exitosamente.'];
        });
    }

    /** Cambio de contraseña hecho por un administrador. */
    public function cambiarPassword(int $id, string $password, string $confirmacion): array
    {
        ReglasEmpleado::validarPassword($password, $confirmacion);

        return $this->enTransaccion(function () use ($id, $password): array {
            $empleado = $this->usuarios->findById($id);
            if ($empleado === null) {
                throw new Exception("El empleado no existe en el sistema.");
            }
            if (!$empleado->changePasswordAdmin($password)) {
                throw new Exception("No se pudo actualizar la contraseña. Intente nuevamente.");
            }
            // Sin detalle: nunca se guarda la contraseña, solo que se cambió
            $this->auditoria->registrar('empleado.cambio_password', 'empleado', $id,
                'Cambio de contraseña por un administrador', null, []);
            return ['success' => true, 'message' => 'Contraseña de ' . $empleado->getNombre() . ' actualizada con éxito.'];
        });
    }

    /**
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    private function validar(array $datos, bool $requierePassword): array
    {
        $idNacionalidad = (int)($datos['id_nacionalidad'] ?? 0);
        $d = ReglasEmpleado::normalizar($datos, $this->ubicacion->esArgentina($idNacionalidad), $requierePassword, date('Y-m-d'));
        $this->ubicacion->validar($d['id_nacionalidad'], $d['id_provincia'], $d['id_localidad']);
        return $d;
    }

    /** @param array<string, mixed> $d Datos ya validados. */
    private function armar(array $d): Usuario
    {
        $usuario = new Usuario();
        $usuario->setNombre($d['nombre']);
        $usuario->setApellido($d['apellido']);
        $usuario->setEmail($d['email']);
        $usuario->setIdRol($d['id_rol']);
        $usuario->setIdProvincia($d['id_provincia']);
        $usuario->setIdLocalidad($d['id_localidad']);
        $usuario->setIdNacionalidad($d['id_nacionalidad']);
        $usuario->setTelefono($d['telefono']);
        $usuario->setDni($d['dni']);
        $usuario->setCuil($d['cuil']);
        $usuario->setDireccion($d['direccion']);
        $usuario->setFechaNacimiento($d['fecha_nacimiento']);
        return $usuario;
    }

    private function enTransaccion(callable $operacion): array
    {
        $this->db->beginTransaction();
        try {
            $resultado = $operacion();
            $this->db->commit();
            return $resultado;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
