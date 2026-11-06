<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Politicas;
use App\Dominio\ConfiguracionVersionada;
use App\Dominio\PoliticaReserva;
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
        'hora_no_show'               => 'HORA_NO_SHOW',
        'max_noches_reserva'         => 'MAX_NOCHES_RESERVA',
        'sena_minima_pct'            => 'SENA_MINIMA_PCT',
        'cancelacion_gratis_horas'   => 'CANCELACION_GRATIS_HORAS',
        'cancelacion_penalidad_noches' => 'CANCELACION_PENALIDAD_NOCHES',
        'noshow_noches_retenidas'    => 'NOSHOW_NOCHES_RETENIDAS',
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
                "SELECT valor, tipo, vigente_desde FROM Configuracion
                 WHERE clave = :clave AND vigente_desde <= :ref"
            );
            $stmt->execute([':clave' => $clave, ':ref' => $ref]);
            $valor = ConfiguracionVersionada::valorVigente($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $ref, $valor);
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

    /** Hora de checkout vigente hoy, formato HH:MM. */
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

    /** Hora del día de entrada desde la que se puede marcar No-Show, formato HH:MM. */
    public static function horaNoShow(): string
    {
        return (string)self::get('hora_no_show');
    }

    /** Máximo de noches por reserva. */
    public static function maxNochesReserva(): int
    {
        return (int)self::get('max_noches_reserva');
    }

    // Condiciones aceptadas por el huésped: se leen a la fecha_alta de la reserva
    // ($fechaAlta), así un cambio de política no afecta a reservas ya hechas.

    /** Seña mínima para confirmar, en % del total (vigente al crear la reserva). */
    public static function senaMinimaPct(?string $fechaAlta = null): float
    {
        return (float)self::get('sena_minima_pct', $fechaAlta);
    }

    /** Horas de anticipación (hasta las 00:00 del día de entrada) para cancelar sin cargo (vigente al crear la reserva). */
    public static function cancelacionGratisHoras(?string $fechaAlta = null): int
    {
        return (int)self::get('cancelacion_gratis_horas', $fechaAlta);
    }

    /** Noches retenidas al cancelar fuera del plazo sin cargo (vigente al crear la reserva). */
    public static function cancelacionPenalidadNoches(?string $fechaAlta = null): int
    {
        return (int)self::get('cancelacion_penalidad_noches', $fechaAlta);
    }

    /** Noches que se retiene la habitación (y se cobran) en un No-Show con pago (vigente al crear la reserva). */
    public static function noShowNochesRetenidas(?string $fechaAlta = null): int
    {
        return (int)self::get('noshow_noches_retenidas', $fechaAlta);
    }

    /** Condiciones de la reserva vigentes a su $fechaAlta (null = las de hoy). */
    public static function politicaReserva(?string $fechaAlta = null): PoliticaReserva
    {
        return new PoliticaReserva(
            self::senaMinimaPct($fechaAlta),
            self::cancelacionGratisHoras($fechaAlta),
            self::cancelacionPenalidadNoches($fechaAlta),
            self::noShowNochesRetenidas($fechaAlta),
            self::factorLateCheckout($fechaAlta),
        );
    }
}
