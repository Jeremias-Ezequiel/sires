<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Reglas de los comprobantes PDF, sin acceso a la base: tipos, formato del número y nombre
 * del archivo. Numeración correlativa propia por tipo y punto de venta (no son facturas fiscales).
 */
final class Comprobante
{
    public const PAGO = 'pago';
    public const REEMBOLSO = 'reembolso';
    public const RESUMEN = 'resumen';

    /** Tipo => título impreso en el PDF. */
    public const TITULOS = [
        self::PAGO      => 'Recibo de pago',
        self::REEMBOLSO => 'Comprobante de reembolso',
        self::RESUMEN   => 'Resumen de la reserva',
    ];

    /** Leyenda obligatoria: el sistema no emite facturas. */
    public const LEYENDA = 'Documento no válido como factura.';

    /** 0001-00000012 */
    public static function numeroFormateado(int $puntoVenta, int $numero): string
    {
        return sprintf('%04d-%08d', $puntoVenta, $numero);
    }

    /** Próximo número a partir del último emitido (null = ninguno todavía). */
    public static function siguienteNumero(?int $ultimo): int
    {
        return ($ultimo ?? 0) + 1;
    }

    /** Tipo de comprobante de una transacción: positiva = pago, negativa = reembolso. */
    public static function tipoDeTransaccion(float $monto): string
    {
        if ($monto == 0.0) {
            throw new \InvalidArgumentException('Una transacción de monto 0 no tiene comprobante.');
        }
        return $monto > 0 ? self::PAGO : self::REEMBOLSO;
    }

    /** Nombre del archivo: recibo-de-pago-0001-00000012.pdf */
    public static function nombreArchivo(string $tipo, int $puntoVenta, int $numero): string
    {
        $titulo = self::TITULOS[$tipo] ?? 'comprobante';
        $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', strtr($titulo, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'])));
        return trim($slug, '-') . '-' . self::numeroFormateado($puntoVenta, $numero) . '.pdf';
    }
}
