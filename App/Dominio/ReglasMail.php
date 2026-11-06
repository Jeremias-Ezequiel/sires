<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Reglas de la cola de mails, sin acceso a la base: reintentos, ventana del recordatorio
 * y claves únicas (evitan encolar dos veces el mismo mail).
 */
final class ReglasMail
{
    /** Intentos de envío antes de dar el mail por fallido (estado 'error'). */
    public const MAX_INTENTOS = 5;

    /** Espera antes del próximo intento, según cuántos fallaron: 1 → 5 min, 2 → 15, 3 → 60, 4 → 240. */
    public const ESPERAS_MINUTOS = [1 => 5, 2 => 15, 3 => 60, 4 => 240];

    /** Largo máximo de Cola_Mails.ultimo_error. */
    public const LARGO_ERROR = 500;

    /**
     * Momento del próximo intento (timestamp) después de $intentosFallidos fallos,
     * o null si ya no se reintenta.
     */
    public static function proximoIntento(int $intentosFallidos, int $ahora): ?int
    {
        if ($intentosFallidos >= self::MAX_INTENTOS) {
            return null;
        }
        $minutos = self::ESPERAS_MINUTOS[$intentosFallidos] ?? ($intentosFallidos < 1 ? 1 : max(self::ESPERAS_MINUTOS));
        return $ahora + $minutos * 60;
    }

    /**
     * Fechas de entrada que reciben recordatorio hoy: de mañana hasta hoy + N días.
     * Así una corrida perdida no deja reservas sin aviso (la clave única evita repetirlo).
     *
     * @return array{desde: string, hasta: string}
     */
    public static function ventanaRecordatorio(string $hoy, int $diasAntes): array
    {
        $base = new \DateTimeImmutable($hoy);
        return [
            'desde' => $base->modify('+1 day')->format('Y-m-d'),
            'hasta' => $base->modify('+' . max(1, $diasAntes) . ' day')->format('Y-m-d'),
        ];
    }

    /**
     * Si la reserva se hizo antes de que empiece su ventana de recordatorio.
     * Una reserva hecha a pocos días de la entrada ya recibió la confirmación: no se le recuerda.
     */
    public static function correspondeRecordatorio(string $fechaAlta, string $fechaEntrada, int $diasAntes): bool
    {
        $inicioVentana = (new \DateTimeImmutable($fechaEntrada))->modify('-' . max(1, $diasAntes) . ' day')->format('Y-m-d');
        return substr($fechaAlta, 0, 10) < $inicioVentana;
    }

    /**
     * Motivo para no mandar un mail que sigue en la cola, o null si se manda.
     * La confirmación y el recordatorio de una reserva cancelada ya no tienen sentido;
     * el comprobante de pago sí (el pago existió).
     */
    public static function motivoDescarte(string $tipo, ?int $idEstadoReserva): ?string
    {
        if ($tipo !== 'comprobante_pago' && $idEstadoReserva === \App\Models\EstadoReserva::CANCELADA) {
            return 'No se envió: la reserva se canceló antes.';
        }
        return null;
    }

    public static function claveConfirmacion(int $idReserva): string
    {
        return 'confirmacion:' . $idReserva;
    }

    /** Incluye la fecha de entrada: si la reserva se mueve de fecha, corresponde otro recordatorio. */
    public static function claveRecordatorio(int $idReserva, string $fechaEntrada): string
    {
        return 'recordatorio:' . $idReserva . ':' . $fechaEntrada;
    }

    public static function claveComprobantePago(int $idTransaccion): string
    {
        return 'pago:' . $idTransaccion;
    }

    /** Mensaje de error recortado para guardar, sin saltos de línea. */
    public static function recortarError(string $error): string
    {
        $limpio = trim((string)preg_replace('/\s+/', ' ', $error));
        return mb_substr($limpio, 0, self::LARGO_ERROR);
    }
}
