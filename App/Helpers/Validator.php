<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTime;
use DateTimeZone;
use Exception;

/**
 * Capa central de validación y normalización de datos de entrada.
 * Fuente única de verdad para controllers, modelos y, en lo posible, el frontend.
 */
class Validator
{
    public const MAX_NOMBRE       = 50;
    public const MAX_APELLIDO     = 50;
    public const MAX_EMAIL        = 254;
    public const MAX_DIRECCION    = 200;
    public const MAX_OBSERVACIONES = 500;
    public const MAX_PASSWORD     = 72; // Límite de bytes de bcrypt
    public const MIN_PASSWORD     = 8;
    public const MIN_EDAD         = 18;
    public const MAX_EDAD         = 130;

    /** Prefijos válidos de CUIL (personas físicas en AFIP). */
    public const PREFIJOS_CUIL = [20, 23, 24, 27];

    public const REGEX_LETRAS_ESPACIOS = '/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u';

    /** Valida que un valor solo contenga letras y espacios, respetando un largo máximo. */
    public static function letrasYEspacios(string $valor, string $campo, int $max = self::MAX_NOMBRE): string
    {
        $clean = trim($valor);
        if ($clean === '') {
            throw new Exception("El {$campo} es obligatorio.");
        }
        if (mb_strlen($clean, 'UTF-8') > $max) {
            throw new Exception("El {$campo} no puede superar los {$max} caracteres.");
        }
        if (!preg_match(self::REGEX_LETRAS_ESPACIOS, $clean)) {
            throw new Exception("El {$campo} solo puede contener letras y espacios.");
        }
        return $clean;
    }

    /** Normaliza y valida un DNI argentino (6 a 8 dígitos). Se eliminan los separadores de miles. */
    public static function dniEmpleado(string $dni): string
    {
        $digits = preg_replace('/[^0-9]/', '', $dni);
        if (!preg_match('/^\d{6,8}$/', $digits)) {
            throw new Exception('El DNI debe contener entre 6 y 8 dígitos (ej: 35123456).');
        }
        return $digits;
    }

    /** Normaliza (a mayúsculas) y valida el documento de un cliente (normativa OACI 9303). */
    public static function documentoCliente(string $documento): string
    {
        $clean = mb_strtoupper(trim($documento), 'UTF-8');
        if (!preg_match('/^(?:[0-9]{6,8}|[0-9]{1,3}(?:\.[0-9]{3}){1,2}|[A-Z0-9]{5,9})$/', $clean)) {
            throw new Exception('El formato del DNI o Pasaporte no es válido (normativa OACI 9303).');
        }
        return $clean;
    }

    /**
     * Normaliza y valida un CUIL argentino verificando el dígito verificador (módulo 11).
     * Devuelve el CUIL formateado como XX-XXXXXXXX-X.
     */
    public static function cuilValido(string $cuil): string
    {
        $digits = preg_replace('/\D/', '', $cuil);
        if (strlen($digits) !== 11) {
            throw new Exception('El CUIL debe tener 11 dígitos en el formato XX-XXXXXXXX-X.');
        }

        $prefijo = (int)substr($digits, 0, 2);
        if (!in_array($prefijo, self::PREFIJOS_CUIL, true)) {
            throw new Exception('El CUIL no corresponde a un prefijo válido (20, 23, 24 o 27).');
        }

        $suma = 0;
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        for ($i = 0; $i < 10; $i++) {
            $suma += (int)$digits[$i] * $pesos[$i];
        }

        $resto = $suma % 11;
        if ($resto === 0) {
            $verificador = 0;
        } elseif ($resto === 1) {
            $verificador = in_array($prefijo, [23, 24], true) ? 9 : 4;
        } else {
            $verificador = 11 - $resto;
        }

        if ((int)$digits[10] !== $verificador) {
            throw new Exception('El dígito verificador del CUIL no es válido.');
        }

        return substr($digits, 0, 2) . '-' . substr($digits, 2, 8) . '-' . $digits[10];
    }

    /** Normaliza un teléfono al estándar internacional E.164. Devuelve null si viene vacío. */
    public static function telefonoE164(string $telefono): ?string
    {
        $clean = trim($telefono);
        if ($clean === '') {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $clean);
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw new Exception('El teléfono debe cumplir el formato internacional UIT-T E.164 (ej: +5491123456789).');
        }
        return '+' . $digits;
    }

    /** Valida un email y lo devuelve normalizado en minúsculas. */
    public static function emailValido(string $email): string
    {
        $clean = mb_strtolower(trim($email), 'UTF-8');
        if ($clean === '') {
            throw new Exception('El correo electrónico es obligatorio.');
        }
        if (mb_strlen($clean, 'UTF-8') > self::MAX_EMAIL) {
            throw new Exception('El correo electrónico no puede superar los ' . self::MAX_EMAIL . ' caracteres.');
        }
        if (!filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('El formato del correo electrónico no es válido.');
        }
        return $clean;
    }

    /** Valida una fecha de nacimiento real (entre 18 y 130 años). Acepta strings vacíos (opcional). */
    public static function fechaNacimientoValida(string $fecha): string
    {
        $clean = trim($fecha);
        if ($clean === '') {
            return '';
        }

        $dt = DateTime::createFromFormat('Y-m-d', $clean, new DateTimeZone('America/Argentina/Buenos_Aires'));
        if (!$dt || $dt->format('Y-m-d') !== $clean) {
            throw new Exception('La fecha de nacimiento no es válida (AAAA-MM-DD).');
        }
        if (!checkdate((int)$dt->format('m'), (int)$dt->format('d'), (int)$dt->format('Y'))) {
            throw new Exception('La fecha de nacimiento no es válida (AAAA-MM-DD).');
        }

        $hoy = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $hoy->setTime(0, 0, 0);
        if ($dt > $hoy) {
            throw new Exception('La fecha de nacimiento no puede ser futura.');
        }

        $edad = $hoy->diff($dt)->y;
        if ($edad < self::MIN_EDAD) {
            throw new Exception('El usuario debe ser mayor de edad (' . self::MIN_EDAD . ' años o más).');
        }
        if ($edad > self::MAX_EDAD) {
            throw new Exception('La fecha de nacimiento indica una edad mayor a ' . self::MAX_EDAD . ' años.');
        }

        return $clean;
    }

    /** Valida la fortaleza de una contraseña (mín. 8, máx. 72, mayúscula y número). */
    public static function passwordValida(string $password): void
    {
        if ($password === '') {
            throw new Exception('La contraseña es obligatoria.');
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new Exception('La contraseña debe tener al menos ' . self::MIN_PASSWORD . ' caracteres.');
        }
        if (strlen($password) > self::MAX_PASSWORD) {
            throw new Exception('La contraseña no puede superar los ' . self::MAX_PASSWORD . ' caracteres.');
        }
        if (!preg_match('/[A-Z]/', $password)) {
            throw new Exception('La contraseña debe contener al menos una letra mayúscula.');
        }
        if (!preg_match('/\d/', $password)) {
            throw new Exception('La contraseña debe contener al menos un número.');
        }
    }

    /** Normaliza una dirección opcional respetando el largo máximo. */
    public static function direccionValida(string $direccion): ?string
    {
        $clean = trim($direccion);
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean, 'UTF-8') > self::MAX_DIRECCION) {
            throw new Exception('La dirección no puede superar los ' . self::MAX_DIRECCION . ' caracteres.');
        }
        return htmlspecialchars($clean, ENT_QUOTES, 'UTF-8');
    }

    /** Normaliza observaciones opcionales respetando el largo máximo. */
    public static function observacionesValidas(string $observaciones): ?string
    {
        $clean = trim($observaciones);
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean, 'UTF-8') > self::MAX_OBSERVACIONES) {
            throw new Exception('Las observaciones no pueden superar los ' . self::MAX_OBSERVACIONES . ' caracteres.');
        }
        return htmlspecialchars($clean, ENT_QUOTES, 'UTF-8');
    }
}