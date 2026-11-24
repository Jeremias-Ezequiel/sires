<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Localidad;
use App\Models\Nacionalidad;
use App\Models\Provincia;
use Exception;

/**
 * Cascada nacionalidad → provincia → localidad de los formularios de empleados y clientes.
 */
final class UbicacionService
{
    private Nacionalidad $nacionalidades;

    public function __construct()
    {
        $this->nacionalidades = new Nacionalidad();
    }

    public function esArgentina(int $idNacionalidad): bool
    {
        return $idNacionalidad > 0 && $this->nacionalidades->esArgentina($idNacionalidad);
    }

    /**
     * Verifica que la nacionalidad exista, que la provincia sea de esa nacionalidad y que la
     * localidad (opcional) sea de esa provincia.
     * @throws Exception con el mensaje para el usuario.
     */
    public function validar(int $idNacionalidad, int $idProvincia, ?int $idLocalidad): void
    {
        if (!$this->nacionalidades->exists($idNacionalidad)) {
            throw new Exception("La nacionalidad seleccionada no es válida.");
        }

        $provincias = (new Provincia())->getByPais($idNacionalidad) ?? [];
        if (!self::contieneId($provincias, $idProvincia)) {
            throw new Exception("La provincia seleccionada no corresponde a la nacionalidad elegida.");
        }

        if ($idLocalidad !== null) {
            $localidades = (new Localidad())->getByProvincia($idProvincia) ?? [];
            if (!self::contieneId($localidades, $idLocalidad)) {
                throw new Exception("La localidad seleccionada no corresponde a la provincia elegida.");
            }
        }
    }

    /** @param array<object> $filas Modelos con getId(). */
    private static function contieneId(array $filas, int $id): bool
    {
        foreach ($filas as $fila) {
            if (method_exists($fila, 'getId') && (int)$fila->getId() === $id) {
                return true;
            }
        }
        return false;
    }
}
