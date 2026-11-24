<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Dominio\ReglasMail;
use App\Dominio\Tarifa;
use App\Models\ColaMail;
use App\Models\Reserva;
use App\Services\Mail\SmtpTransporte;
use App\Services\Mail\Transporte;
use Exception;
use PDO;
use Throwable;

/**
 * Mails al huésped: confirmación de la reserva, recordatorio N días antes de la entrada
 * (Configuracion.recordatorio_dias_antes) y comprobante de cada pago.
 *
 * - encolar*(): arma el mail con las plantillas de App/views/mail/ y lo guarda en Cola_Mails.
 *   Se llama dentro de la transacción de la operación; si falla, se loguea y la operación
 *   sigue (el mail no es crítico).
 * - procesarCola(): lo corre cron/mails.php cada 5 minutos. Reintenta con espera creciente
 *   (App\Dominio\ReglasMail) y registra la corrida en Ejecuciones_Automaticas.
 */
final class MailService
{
    /** Nombre del lock de MySQL que evita dos corridas simultáneas de la cola. */
    private const LOCK = 'sires_cola_mails';

    private ColaMail $cola;
    private Transporte $transporte;

    public function __construct(?Transporte $transporte = null)
    {
        $this->cola = new ColaMail();
        $this->transporte = $transporte ?? new SmtpTransporte();
    }

    // ------------------------------------------------------------------
    // Encolar
    // ------------------------------------------------------------------

    public function encolarConfirmacion(int $idReserva): bool
    {
        return $this->encolarSeguro(function () use ($idReserva): bool {
            $datos = $this->datosReserva($idReserva);
            return $this->cola->encolar(
                ColaMail::TIPO_CONFIRMACION,
                $idReserva,
                ReglasMail::claveConfirmacion($idReserva),
                $datos['reserva']['cliente_email'],
                'Tu reserva #' . $idReserva . ' en ' . $datos['hotel'],
                $this->render('confirmacion', $datos)
            );
        }, 'confirmación de la reserva #' . $idReserva);
    }

    public function encolarComprobantePago(int $idReserva, int $idTransaccion): bool
    {
        return $this->encolarSeguro(function () use ($idReserva, $idTransaccion): bool {
            $datos = $this->datosReserva($idReserva);
            $stmt = $this->db()->prepare(
                "SELECT t.id, t.monto_abonado, t.fecha_hora, mp.descripcion AS metodo
                 FROM Transacciones_Pago t
                 LEFT JOIN Metodos_Pago mp ON mp.id = t.id_metodo_pago
                 WHERE t.id = :id"
            );
            $stmt->execute([':id' => $idTransaccion]);
            $pago = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pago) {
                throw new Exception('la transacción no existe');
            }
            $datos['pago'] = $pago;
            return $this->cola->encolar(
                ColaMail::TIPO_COMPROBANTE_PAGO,
                $idReserva,
                ReglasMail::claveComprobantePago($idTransaccion),
                $datos['reserva']['cliente_email'],
                'Recibimos tu pago - Reserva #' . $idReserva,
                $this->render('comprobante_pago', $datos)
            );
        }, 'comprobante del pago #' . $idTransaccion);
    }

    /**
     * Encola los recordatorios de las reservas pendientes o confirmadas que entran dentro de
     * los próximos N días y se hicieron antes de esa ventana. La clave única evita repetirlos.
     */
    public function encolarRecordatorios(): int
    {
        $dias = ConfiguracionService::recordatorioDiasAntes();
        $ventana = ReglasMail::ventanaRecordatorio(date('Y-m-d'), $dias);
        $stmt = $this->db()->prepare(
            "SELECT id, fecha_entrada, fecha_alta FROM Reservas
             WHERE id_estado_reserva IN (:pendiente, :confirmada)
               AND fecha_entrada BETWEEN :desde AND :hasta"
        );
        $stmt->execute([
            ':pendiente' => Reserva::ESTADO_PENDIENTE, ':confirmada' => Reserva::ESTADO_CONFIRMADA,
            ':desde' => $ventana['desde'], ':hasta' => $ventana['hasta'],
        ]);

        $encolados = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!ReglasMail::correspondeRecordatorio((string)$r['fecha_alta'], (string)$r['fecha_entrada'], $dias)) {
                continue;
            }
            $id = (int)$r['id'];
            $ok = $this->encolarSeguro(function () use ($id, $r): bool {
                $datos = $this->datosReserva($id);
                return $this->cola->encolar(
                    ColaMail::TIPO_RECORDATORIO,
                    $id,
                    ReglasMail::claveRecordatorio($id, (string)$r['fecha_entrada']),
                    $datos['reserva']['cliente_email'],
                    'Te esperamos el ' . date('d/m', strtotime((string)$r['fecha_entrada'])) . ' - Reserva #' . $id,
                    $this->render('recordatorio', $datos)
                );
            }, 'recordatorio de la reserva #' . $id);
            $encolados += $ok ? 1 : 0;
        }
        return $encolados;
    }

    // ------------------------------------------------------------------
    // Envío
    // ------------------------------------------------------------------

    /**
     * Encola los recordatorios del día y manda los mails pendientes.
     * Lanza una excepción si hubo errores y ningún envío salió bien (ej. SMTP caído),
     * para que la corrida quede como fallida en el monitoreo.
     *
     * @return array{success: bool, procesadas: int, enviados: int, errores: int, recordatorios: int, message: string}
     */
    public function procesarCola(int $limite = 30): array
    {
        $db = $this->db();
        $lock = $db->query("SELECT GET_LOCK('" . self::LOCK . "', 0)");
        if ($lock === false || (int)$lock->fetchColumn() !== 1) {
            return ['success' => true, 'procesadas' => 0, 'enviados' => 0, 'errores' => 0, 'recordatorios' => 0,
                'message' => 'Otra corrida de la cola de mails está en curso.'];
        }

        try {
            $recordatorios = $this->encolarRecordatorios();
            $enviados = 0;
            $errores = 0;
            $ultimoError = '';

            foreach ($this->cola->listosParaEnviar($limite) as $m) {
                $id = (int)$m['id'];
                $estadoReserva = $m['id_estado_reserva'] === null ? null : (int)$m['id_estado_reserva'];
                $descarte = ReglasMail::motivoDescarte((string)$m['tipo'], $estadoReserva);
                if ($descarte !== null) {
                    $this->cola->registrarFallo($id, (int)$m['intentos'], null, $descarte);
                    continue;
                }
                try {
                    $this->transporte->enviar((string)$m['destinatario'], (string)$m['asunto'], (string)$m['cuerpo_html'], $this->adjuntos($m));
                    $this->cola->marcarEnviado($id);
                    $enviados++;
                } catch (Throwable $e) {
                    $intentos = (int)$m['intentos'] + 1;
                    $proximo = ReglasMail::proximoIntento($intentos, time());
                    $ultimoError = ReglasMail::recortarError($e->getMessage());
                    $this->cola->registrarFallo($id, $intentos, $proximo === null ? null : date('Y-m-d H:i:s', $proximo), $ultimoError);
                    $errores++;
                    error_log('[SIRES MAIL] Mail #' . $id . ' falló (intento ' . $intentos . '): ' . $ultimoError);
                }
            }

            if ($errores > 0 && $enviados === 0) {
                throw new Exception('No se pudo enviar ningún mail (' . $errores . ' con error): ' . $ultimoError);
            }

            return [
                'success'       => true,
                'procesadas'    => $enviados + $errores,
                'enviados'      => $enviados,
                'errores'       => $errores,
                'recordatorios' => $recordatorios,
                'message'       => "Cola de mails: {$enviados} enviado(s), {$errores} con error, {$recordatorios} recordatorio(s) encolado(s).",
            ];
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
    }

    // ------------------------------------------------------------------
    // Plantillas
    // ------------------------------------------------------------------

    /**
     * HTML de una plantilla de App/views/mail/ dentro de mail/layout.phtml.
     *
     * @param array<string, mixed> $datos
     */
    public function render(string $plantilla, array $datos): string
    {
        $base = dirname(__DIR__) . '/views/mail/';
        $datos += ['hotel' => self::nombreHotel()];
        $datos['contenido'] = Plantilla::render($base . $plantilla . '.phtml', $datos);
        return Plantilla::render($base . 'layout.phtml', $datos);
    }

    /**
     * Datos comunes de las plantillas: la reserva con su cliente, noches, condiciones
     * vigentes a su fecha_alta y hora de checkout.
     *
     * @return array<string, mixed>
     */
    private function datosReserva(int $idReserva): array
    {
        $reserva = (new Reserva())->findById($idReserva);
        if (!$reserva) {
            throw new Exception('la reserva no existe');
        }
        if (empty($reserva['cliente_email'])) {
            throw new Exception('el cliente no tiene email');
        }
        return [
            'hotel'        => self::nombreHotel(),
            'reserva'      => $reserva,
            'noches'       => Tarifa::noches((string)$reserva['fecha_entrada'], (string)$reserva['fecha_salida']),
            'politica'     => ConfiguracionService::politicaReserva((string)$reserva['fecha_alta']),
            'horaCheckout' => ConfiguracionService::horaCheckout(),
        ];
    }

    /**
     * Adjuntos de un mail de la cola: el recibo PDF en el comprobante de pago.
     * Si el PDF no se puede generar, el mail sale igual sin adjunto (se loguea).
     *
     * @param array<string, mixed> $mail
     * @return list<array{nombre: string, contenido: string, tipo: string}>
     */
    private function adjuntos(array $mail): array
    {
        if ($mail['tipo'] !== ColaMail::TIPO_COMPROBANTE_PAGO
            || !preg_match('/^pago:(\d+)$/', (string)$mail['clave_unica'], $m)) {
            return [];
        }
        try {
            $comprobantes = new ComprobanteService();
            $comprobante = $comprobantes->porTransaccion((int)$m[1]);
            return $comprobante === null ? [] : [$comprobantes->pdf($comprobante)];
        } catch (Throwable $e) {
            error_log('[SIRES MAIL] Mail #' . $mail['id'] . ' sin adjunto: ' . $e->getMessage());
            return [];
        }
    }

    /** Nombre del hotel (Configuracion.hotel_nombre); si está vacío, el del remitente. */
    private static function nombreHotel(): string
    {
        $nombre = ConfiguracionService::datosHotel()['nombre'];
        return $nombre !== '' ? $nombre : SmtpTransporte::nombreRemitente();
    }

    /** Ejecuta un encolado; si falla lo loguea y devuelve false, sin cortar la operación. */
    private function encolarSeguro(callable $fn, string $que): bool
    {
        try {
            return (bool)$fn();
        } catch (Throwable $e) {
            error_log('[SIRES MAIL] No se pudo encolar ' . $que . ': ' . $e->getMessage());
            return false;
        }
    }

    private function db(): PDO
    {
        return (new Database())->getConnection();
    }
}
