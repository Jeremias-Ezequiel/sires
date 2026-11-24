<?php

declare(strict_types=1);

namespace App\Dominio;

use App\Helpers\Validator;
use Exception;

/**
 * Validación y normalización de los datos de un empleado, sin acceso a la base.
 * Reglas: DNI argentino, CUIL con dígito verificador (obligatorio y derivado del DNI solo
 * para argentinos), teléfono E.164 y contraseña fuerte en el alta.
 * La coherencia nacionalidad / provincia / localidad la verifica UbicacionService.
 */
final class ReglasEmpleado
{
    /**
     * @param array<string, mixed> $post Datos del formulario.
     * @param string $hoy Fecha de referencia (Y-m-d) para la edad.
     * @return array{nombre: string, apellido: string, email: string, telefono: ?string, dni: string,
     *               cuil: string, direccion: ?string, fecha_nacimiento: string, password: string,
     *               id_rol: int, id_provincia: int, id_localidad: ?int, id_nacionalidad: int}
     * @throws Exception con el mensaje para el usuario si un dato no es válido.
     */
    public static function normalizar(array $post, bool $esArgentina, bool $requierePassword, string $hoy): array
    {
        $texto = static fn (string $campo): string => trim((string)($post[$campo] ?? ''));

        $nombre          = $texto('nombre');
        $apellido        = $texto('apellido');
        $dni             = $texto('dni');
        $cuil            = $texto('cuil');
        $fechaNacimiento = $texto('fecha_nacimiento');
        $password        = (string)($post['password'] ?? '');
        $confirmacion    = (string)($post['confirm_password'] ?? '');
        $idRol           = (int)($post['id_rol'] ?? 0);
        $idProvincia     = (int)($post['id_provincia'] ?? 0);
        $idLocalidad     = !empty($post['id_localidad']) ? (int)$post['id_localidad'] : null;
        $idNacionalidad  = (int)($post['id_nacionalidad'] ?? 0);

        // --- Textos libres ---
        Validator::letrasYEspacios($nombre, 'nombre', Validator::MAX_NOMBRE);
        Validator::letrasYEspacios($apellido, 'apellido', Validator::MAX_APELLIDO);
        $email           = Validator::emailValido($texto('email'));
        $telefono        = Validator::telefonoE164($texto('telefono'));
        $direccion       = Validator::direccionValida($texto('direccion'));
        $fechaNacimiento = $fechaNacimiento !== '' ? Validator::fechaNacimientoValida($fechaNacimiento, $hoy) : '';
        $dni             = $dni !== '' ? Validator::dniEmpleado($dni) : '';

        // --- Selects obligatorios ---
        if ($idRol <= 0) {
            throw new Exception("Debe seleccionar un rol para el empleado.");
        }
        if ($idNacionalidad <= 0) {
            throw new Exception("Debe seleccionar una nacionalidad.");
        }
        if ($idProvincia <= 0) {
            throw new Exception("Debe seleccionar una provincia.");
        }

        // --- CUIL: obligatorio y con dígito verificador para argentinos; opcional para extranjeros ---
        if ($esArgentina) {
            if ($cuil === '') {
                throw new Exception("El CUIL es obligatorio para empleados argentinos.");
            }
            $cuil = Validator::cuilValido($cuil);

            // Para argentinos el CUIL debe derivar del DNI ingresado
            if ($dni === '') {
                throw new Exception("El DNI es obligatorio para empleados argentinos.");
            }
            if (!Validator::cuilCoincideConDni($cuil, $dni)) {
                throw new Exception("El CUIL no coincide con el DNI ingresado.");
            }
        } else {
            $cuil = $cuil !== '' ? Validator::cuilValido($cuil) : '';
        }

        // --- Contraseña (solo en el alta) ---
        if ($requierePassword) {
            self::validarPassword($password, $confirmacion);
        }

        return [
            'nombre'           => $nombre,
            'apellido'         => $apellido,
            'email'            => $email,
            'telefono'         => $telefono,
            'dni'              => $dni,
            'cuil'             => $cuil,
            'direccion'        => $direccion,
            'fecha_nacimiento' => $fechaNacimiento,
            'password'         => $password,
            'id_rol'           => $idRol,
            'id_provincia'     => $idProvincia,
            'id_localidad'     => $idLocalidad,
            'id_nacionalidad'  => $idNacionalidad,
        ];
    }

    /**
     * Contraseña fuerte y repetida igual (alta, cambio por el administrador y recuperación).
     * @throws Exception
     */
    public static function validarPassword(string $password, string $confirmacion): void
    {
        Validator::passwordValida($password);
        if ($password !== $confirmacion) {
            throw new Exception("Las contraseñas ingresadas no coinciden.");
        }
    }
}
