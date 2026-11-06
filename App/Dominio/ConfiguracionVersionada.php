<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Resolución de la tabla Configuracion sin acceso a la base: de las filas de una clave,
 * vale la de mayor vigente_desde <= fecha de referencia, convertida según su tipo.
 * La usa ConfiguracionService.
 */
final class ConfiguracionVersionada
{
    /**
     * Valor vigente en $fechaReferencia (Y-m-d o Y-m-d H:i:s), o $porDefecto si ninguna fila aplica.
     *
     * @param list<array{valor: string, tipo: string, vigente_desde: string}> $filas
     * @throws \JsonException|\UnexpectedValueException si el valor guardado no es válido para su tipo
     */
    public static function valorVigente(array $filas, string $fechaReferencia, mixed $porDefecto): mixed
    {
        $fila = self::filaVigente($filas, $fechaReferencia);
        return $fila === null ? $porDefecto : self::convertir((string)$fila['valor'], (string)$fila['tipo']);
    }

    /**
     * Fila con mayor vigente_desde <= $fechaReferencia, o null.
     *
     * @param list<array{valor: string, tipo: string, vigente_desde: string}> $filas
     * @return array{valor: string, tipo: string, vigente_desde: string}|null
     */
    public static function filaVigente(array $filas, string $fechaReferencia): ?array
    {
        $ref = self::normalizar($fechaReferencia);
        $elegida = null;
        foreach ($filas as $fila) {
            $desde = self::normalizar((string)$fila['vigente_desde']);
            if ($desde <= $ref && ($elegida === null || $desde > self::normalizar((string)$elegida['vigente_desde']))) {
                $elegida = $fila;
            }
        }
        return $elegida;
    }

    /**
     * Convierte el valor guardado como texto según su tipo (int, decimal, time, json o texto).
     *
     * @throws \JsonException|\UnexpectedValueException
     */
    public static function convertir(string $valor, string $tipo): mixed
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

    /** Una fecha sola (Y-m-d) vale como las 00:00:00 de ese día, igual que en MySQL. */
    private static function normalizar(string $fecha): string
    {
        return strlen($fecha) === 10 ? $fecha . ' 00:00:00' : $fecha;
    }
}
