<?php

declare(strict_types=1);

namespace App\Dominio;

use App\Helpers\Validator;
use Exception;

/**
 * Validación y normalización de los datos de un cliente (huésped), sin acceso a la base.
 * Documento según OACI 9303 (DNI o pasaporte), teléfono E.164 opcional.
 * La coherencia nacionalidad / provincia / localidad la verifica UbicacionService.
 */
final class ReglasCliente
{
    /**
     * @param array<string, mixed> $post Datos del formulario (el correo llega como 'mail').
     * @return array{nombre: string, apellido: string, dni_pasaporte: string, telefono: ?string,
     *               email: string, observaciones: ?string, id_nacionalidad: int, id_provincia: int,
     *               id_localidad: ?int}
     * @throws Exception con el mensaje para el usuario si un dato no es válido.
     */
    public static function normalizar(array $post): array
    {
        $texto = static fn (string $campo): string => trim((string)($post[$campo] ?? ''));

        $nombre         = $texto('nombre');
        $apellido       = $texto('apellido');
        $idNacionalidad = (int)($post['id_nacionalidad'] ?? 0);
        $idProvincia    = (int)($post['id_provincia'] ?? 0);
        $idLocalidad    = !empty($post['id_localidad']) ? (int)$post['id_localidad'] : null;

        // --- Textos libres ---
        Validator::letrasYEspacios($nombre, 'nombre', Validator::MAX_NOMBRE);
        Validator::letrasYEspacios($apellido, 'apellido', Validator::MAX_APELLIDO);
        $dni           = Validator::documentoCliente($texto('dni_pasaporte'));
        $telefono      = Validator::telefonoE164($texto('telefono'));
        $email         = Validator::emailValido($texto('mail'));
        $observaciones = Validator::observacionesValidas($texto('observaciones'));

        // --- Selects obligatorios ---
        if ($idNacionalidad <= 0) {
            throw new Exception("Debe seleccionar una nacionalidad.");
        }
        if ($idProvincia <= 0) {
            throw new Exception("Debe seleccionar una provincia.");
        }

        return [
            'nombre'          => $nombre,
            'apellido'        => $apellido,
            'dni_pasaporte'   => $dni,
            'telefono'        => $telefono,
            'email'           => $email,
            'observaciones'   => $observaciones,
            'id_nacionalidad' => $idNacionalidad,
            'id_provincia'    => $idProvincia,
            'id_localidad'    => $idLocalidad,
        ];
    }
}
