<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Politicas;
use JsonException;
use PDO;
use PDOException;

/**
 * Lee las políticas de negocio versionadas de la tabla Configuracion.
 *
 * - Cada clave puede tener varias filas; vale la de mayor vigente_desde <= fecha de referencia.
 * - Un cambio de política es un INSERT con un vigente_desde nuevo (nunca UPDATE ni DELETE).
 * - Si la tabla o la clave no existen, o el valor es inválido, usa el valor de App\Config\Politicas.
 *
 * Los EVENTs de la BD (corte_pagos, cargo_late_checkout, liberacion_no_show) leen la misma tabla.
 */
final class ConfiguracionService
{
    /** Clave en BD => constante de Politicas usada como fallback. */
    private const FALLBACK = [
        'horas_corte_pago'           => 'HORAS_CORTE_PAGO',
        'hora_checkout'              => 'HORA_CHECKOUT',
        'factor_late_checkout'       => 'FACTOR_LATE_CHECKOUT',
        'descuento_ocupacion'        => 'DESCUENTO_OCUPACION',
        'minutos_token_recuperacion' => 'MINUTOS_TOKEN_RECUPERACION',
    ];

    /** @var array<string, mixed> Caché por petición: "clave@fecha" => valor. */
    private static array $cache = [];

    /**
     * Valor vigente de $clave en $fechaReferencia (Y-m-d H:i:s). null = ahora.
     * Para reglas que dependen de la reserva, pasar su fecha_alta.
     */
    public static function get(string $clave, ?string $fechaReferencia = null): mixed
    {
        if (!isset(self::FALLBACK[$clave])) {
            throw new \InvalidArgumentException("Clave de configuración desconocida: {$clave}");
        }

        $ref = $fechaReferencia ?? date('Y-m-d H:i:s');
        $cacheKey = $clave . '@' . $ref;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $valor = constant(Politicas::class . '::' . self::FALLBACK[$clave]);

        try {
            $db = (new Database())->getConnection();
            $stmt = $db->prepare(
                "SELECT valor, tipo FROM Configuracion
                 WHERE clave = :clave AND vigente_desde <= :ref
                 ORDER BY vigente_desde DESC
                 LIMIT 1"
            );
            $stmt->execute([':clave' => $clave, ':ref' => $ref]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $valor = self::convertir((string)$row['valor'], (string)$row['tipo']);
            }
        } catch (PDOException | JsonException | \UnexpectedValueException $e) {
            error_log('[SIRES CONFIG] Usando valor por defecto de ' . $clave . ': ' . $e->getMessage());
        }

        return self::$cache[$cacheKey] = $valor;
    }

    // ------------------------------------------------------------------
    // Accesos tipados
    // ------------------------------------------------------------------

    /** Horas antes del check-in en que se cancela una reserva sin pago total. */
    public static function horasCortePago(): int
    {
        return (int)self::get('horas_corte_pago');
    }

    /** Hora de checkout vigente hoy, formato HH:MM. También es la hora mínima de No-Show. */
    public static function horaCheckout(): string
    {
        return (string)self::get('hora_checkout');
    }

    /** Hora de checkout con segundos (HH:MM:SS), para comparar en SQL. */
    public static function horaCheckoutSql(): string
    {
        return self::horaCheckout() . ':00';
    }

    /** Factor de late checkout vigente cuando se creó la reserva ($fechaAlta). */
    public static function factorLateCheckout(?string $fechaAlta = null): float
    {
        return (float)self::get('factor_late_checkout', $fechaAlta);
    }

    /** @return array<int, array<int, int|float>> [capacidad => [huespedes => descuento %]] */
    public static function descuentoOcupacion(): array
    {
        return (array)self::get('descuento_ocupacion');
    }

    /** Minutos de validez del token de recuperación de contraseña. */
    public static function minutosTokenRecuperacion(): int
    {
        return (int)self::get('minutos_token_recuperacion');
    }

    // ------------------------------------------------------------------

    /** Convierte el valor guardado como texto según su tipo. */
    private static function convertir(string $valor, string $tipo): mixed
    {
        switch ($tipo) {
            case 'int':
                if (filter_var($valor, FILTER_VALIDATE_INT) === false) {
                    throw new \UnexpectedValueException("'{$valor}' no es un entero");
                }
                return (int)$valor;
            case 'decimal':
                if (!is_numeric($valor)) {
                    throw new \UnexpectedValueException("'{$valor}' no es un decimal");
                }
                return (float)$valor;
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $valor)) {
                    throw new \UnexpectedValueException("'{$valor}' no es una hora HH:MM");
                }
                return substr($valor, 0, 5);
            case 'json':
                $datos = json_decode($valor, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($datos)) {
                    throw new \UnexpectedValueException('el JSON no es un objeto');
                }
                return $datos;
            default:
                return $valor;
        }
    }
}
