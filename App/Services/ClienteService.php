<?php

declare(strict_types=1);

namespace App\Services;

use App\Dominio\ReglasCliente;
use App\Models\Clientes;
use Exception;

/**
 * Alta y edición de clientes (huéspedes).
 */
final class ClienteService
{
    private Clientes $clientes;
    private UbicacionService $ubicacion;

    public function __construct()
    {
        $this->clientes  = new Clientes();
        $this->ubicacion = new UbicacionService();
    }

    /** @param array<string, mixed> $datos Datos del formulario de alta. */
    public function alta(array $datos): array
    {
        if (!$this->clientes->save($this->armar($this->validar($datos)))) {
            throw new Exception("No se pudo guardar el registro. Por favor, revise la información ingresada.");
        }
        return ['success' => true, 'message' => 'Cliente registrado exitosamente.'];
    }

    /** @param array<string, mixed> $datos Datos del formulario de edición. */
    public function editar(int $id, array $datos): array
    {
        $cliente = $this->armar($this->validar($datos));
        $cliente->setId($id);
        if (!$this->clientes->update($cliente)) {
            throw new Exception("No se pudo actualizar el cliente. Verifique los datos ingresados.");
        }
        return ['success' => true, 'message' => 'Cliente actualizado exitosamente.'];
    }

    /**
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    private function validar(array $datos): array
    {
        $d = ReglasCliente::normalizar($datos);
        $this->ubicacion->validar($d['id_nacionalidad'], $d['id_provincia'], $d['id_localidad']);
        return $d;
    }

    /** @param array<string, mixed> $d Datos ya validados. */
    private function armar(array $d): Clientes
    {
        $cliente = new Clientes();
        $cliente->setNombre($d['nombre']);
        $cliente->setApellido($d['apellido']);
        $cliente->setDniPasaporte($d['dni_pasaporte']);
        $cliente->setTelefono($d['telefono']);
        $cliente->setEmail($d['email']);
        $cliente->setObservaciones($d['observaciones']);
        $cliente->setIdNacionalidad($d['id_nacionalidad']);
        $cliente->setIdProvincia($d['id_provincia']);
        $cliente->setIdLocalidad($d['id_localidad']);
        return $cliente;
    }
}
