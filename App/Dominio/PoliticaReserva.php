<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Condiciones que acepta el huésped al reservar, ya resueltas a la fecha_alta de la reserva.
 *
 * En la app se obtiene con ConfiguracionService::politicaReserva($fechaAlta); en los tests
 * se construye con los valores que se quieran probar.
 */
final class PoliticaReserva
{
    public function __construct(
        /** Seña mínima para confirmar, en % del total. */
        public readonly float $senaMinimaPct,
        /** Horas de anticipación (hasta las 00:00 del día de entrada) para cancelar sin cargo. */
        public readonly int $cancelacionGratisHoras,
        /** Noches retenidas al cancelar fuera del plazo sin cargo. */
        public readonly int $cancelacionPenalidadNoches,
        /** Noches que se retiene la habitación (y se cobran) en un No-Show con pago. */
        public readonly int $noShowNochesRetenidas,
        /** Cargo por late checkout, como fracción del precio de la noche. */
        public readonly float $factorLateCheckout,
    ) {
    }
}
