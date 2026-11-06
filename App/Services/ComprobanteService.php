<?php

declare(strict_types=1);

namespace App\Services;

use App\Dominio\Comprobante;
use App\Dominio\Tarifa;
use App\Models\ComprobanteModel;
use App\Models\Reserva;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;
use PDO;

/**
 * Comprobantes PDF: recibo de pago, comprobante de reembolso y resumen de la reserva.
 *
 * - Pagos y reembolsos se numeran en la misma transacción que registra el movimiento
 *   (PagoService::registrarPago / procesarReembolso). Las transacciones anteriores a 1.7.0
 *   se pueden emitir después desde el detalle del pago.
 * - El resumen se emite (con número nuevo) cada vez que se descarga.
 * - Al emitir se guarda una copia de los datos (hotel, reserva, montos): el PDF se arma
 *   siempre desde esa copia, así una reimpresión sale idéntica.
 */
final class ComprobanteService
{
    private ComprobanteModel $modelo;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->modelo = new ComprobanteModel();
        $this->auditoria = new AuditoriaService();
    }

    /**
     * Comprobante de una transacción (pago o reembolso). Si ya existe, lo devuelve.
     * Si hay una transacción abierta se suma a ella; si no, abre la suya.
     *
     * @return array<string, mixed>
     */
    public function emitirDeTransaccion(int $idTransaccion, ?int $emitidoPor): array
    {
        $existente = $this->modelo->porTransaccion($idTransaccion);
        if ($existente !== null) {
            return $existente;
        }

        return $this->enTransaccion(function () use ($idTransaccion, $emitidoPor): array {
            $tx = $this->transaccion($idTransaccion);
            $tipo = Comprobante::tipoDeTransaccion((float)$tx['monto_abonado']);
            $idReserva = (int)$tx['id_reserva'];
            $datos = $this->datosBase($idReserva) + [
                'transaccion' => [
                    'id'          => (int)$tx['id'],
                    'monto'       => abs((float)$tx['monto_abonado']),
                    'fecha_hora'  => (string)$tx['fecha_hora'],
                    'metodo'      => (string)($tx['metodo'] ?? ''),
                    'registrado'  => trim((string)($tx['usuario'] ?? '')),
                ],
            ];
            return $this->emitir($tipo, $idReserva, $idTransaccion, abs((float)$tx['monto_abonado']), $emitidoPor, $datos);
        });
    }

    /**
     * Emite un resumen nuevo de la reserva (número nuevo en cada descarga).
     *
     * @return array<string, mixed>
     */
    public function emitirResumen(int $idReserva, ?int $emitidoPor): array
    {
        return $this->enTransaccion(function () use ($idReserva, $emitidoPor): array {
            $datos = $this->datosBase($idReserva);
            $stmt = $this->db()->prepare(
                "SELECT t.id, t.fecha_hora, t.monto_abonado, mp.descripcion AS metodo
                 FROM Transacciones_Pago t
                 JOIN Resumen_Pago rp ON rp.id = t.id_resumen_pago
                 LEFT JOIN Metodos_Pago mp ON mp.id = t.id_metodo_pago
                 WHERE rp.id_reserva = :id
                 ORDER BY t.fecha_hora, t.id"
            );
            $stmt->execute([':id' => $idReserva]);
            $datos['movimientos'] = array_map(fn (array $m): array => [
                'id' => (int)$m['id'], 'fecha_hora' => (string)$m['fecha_hora'],
                'monto' => (float)$m['monto_abonado'], 'metodo' => (string)($m['metodo'] ?? ''),
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
            return $this->emitir(Comprobante::RESUMEN, $idReserva, null, (float)$datos['pago']['total'], $emitidoPor, $datos);
        });
    }

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        return $this->modelo->porId($id);
    }

    /** @return array<string, mixed>|null */
    public function porTransaccion(int $idTransaccion): ?array
    {
        return $this->modelo->porTransaccion($idTransaccion);
    }

    /** @return list<array<string, mixed>> */
    public function porReserva(int $idReserva): array
    {
        return $this->modelo->porReserva($idReserva);
    }

    /**
     * PDF de un comprobante ya emitido (desde su copia de datos).
     *
     * @param array<string, mixed> $comprobante fila de Comprobantes
     * @return array{nombre: string, contenido: string, tipo: string}
     */
    public function pdf(array $comprobante): array
    {
        $tipo = (string)$comprobante['tipo'];
        $datos = json_decode((string)$comprobante['datos'], true, 512, JSON_THROW_ON_ERROR);
        $datos['comprobante'] = [
            'tipo'   => $tipo,
            'titulo' => Comprobante::TITULOS[$tipo] ?? 'Comprobante',
            'numero' => Comprobante::numeroFormateado((int)$comprobante['punto_venta'], (int)$comprobante['numero']),
            'fecha'  => (string)$comprobante['fecha_emision'],
            'monto'  => (float)$comprobante['monto'],
        ];
        $base = dirname(__DIR__) . '/views/comprobantes/';
        $datos['contenido'] = Plantilla::render($base . ($tipo === Comprobante::RESUMEN ? 'resumen' : 'movimiento') . '.phtml', $datos);
        $html = Plantilla::render($base . 'layout.phtml', $datos);

        $opciones = new Options();
        $opciones->set('defaultFont', 'DejaVu Sans');
        $opciones->set('isRemoteEnabled', false);
        $opciones->setChroot(dirname(__DIR__, 2));
        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return [
            'nombre'    => Comprobante::nombreArchivo($tipo, (int)$comprobante['punto_venta'], (int)$comprobante['numero']),
            'contenido' => (string)$dompdf->output(),
            'tipo'      => 'application/pdf',
        ];
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    private function emitir(string $tipo, int $idReserva, ?int $idTransaccion, float $monto, ?int $emitidoPor, array $datos): array
    {
        $pv = ConfiguracionService::puntoVenta();
        $numero = Comprobante::siguienteNumero($this->modelo->ultimoNumeroParaActualizar($tipo, $pv));
        $id = $this->modelo->insertar($tipo, $pv, $numero, $idReserva, $idTransaccion, $monto, $emitidoPor, $datos);
        $this->auditoria->registrar('comprobante.emitir', 'reserva', $idReserva,
            (Comprobante::TITULOS[$tipo] ?? 'Comprobante') . ' ' . Comprobante::numeroFormateado($pv, $numero));
        $fila = $this->modelo->porId($id);
        if ($fila === null) {
            throw new Exception('No se pudo emitir el comprobante.');
        }
        return $fila;
    }

    /**
     * Copia de los datos del hotel, la reserva y su estado de pago al momento de emitir.
     *
     * @return array<string, mixed>
     */
    private function datosBase(int $idReserva): array
    {
        $r = (new Reserva())->findById($idReserva);
        if (!$r) {
            throw new Exception('La reserva no existe.');
        }
        $retenido = $this->db()->prepare("SELECT monto_retenido FROM Resumen_Pago WHERE id_reserva = :id");
        $retenido->execute([':id' => $idReserva]);

        return [
            'hotel'   => ConfiguracionService::datosHotel(),
            'reserva' => [
                'id'          => (int)$r['id'],
                'cliente'     => trim($r['cliente_nombre'] . ' ' . $r['cliente_apellido']),
                'documento'   => (string)($r['cliente_dni'] ?? ''),
                'email'       => (string)($r['cliente_email'] ?? ''),
                'habitacion'  => '#' . $r['habitacion_numero'] . ' · ' . ($r['tipo_habitacion_descripcion'] ?? ''),
                'entrada'     => (string)$r['fecha_entrada'],
                'salida'      => (string)$r['fecha_salida'],
                'noches'      => Tarifa::noches((string)$r['fecha_entrada'], (string)$r['fecha_salida']),
                'huespedes'   => (int)$r['cantidad_huespedes'],
                'estado'      => (string)$r['estado_descripcion'],
                'precio_noche'=> $r['precio_noche_pactado'] !== null ? (float)$r['precio_noche_pactado'] : null,
            ],
            'pago' => [
                'estado'   => (string)($r['estado_pago_descripcion'] ?? ''),
                'total'    => (float)($r['monto_total'] ?? 0),
                'cobrado'  => (float)($r['monto_cobrado'] ?? 0),
                'saldo'    => (float)($r['saldo_pendiente'] ?? 0),
                'retenido' => (float)($retenido->fetchColumn() ?: 0),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function transaccion(int $id): array
    {
        $stmt = $this->db()->prepare(
            "SELECT t.id, t.monto_abonado, t.fecha_hora, rp.id_reserva, mp.descripcion AS metodo,
                    CONCAT(u.nombre, ' ', u.apellido) AS usuario
             FROM Transacciones_Pago t
             JOIN Resumen_Pago rp ON rp.id = t.id_resumen_pago
             LEFT JOIN Metodos_Pago mp ON mp.id = t.id_metodo_pago
             LEFT JOIN Usuarios u ON u.id = t.registrado_por
             WHERE t.id = :id"
        );
        $stmt->execute([':id' => $id]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            throw new Exception('La transacción no existe.');
        }
        return $fila;
    }

    /** Corre $fn en la transacción abierta o en una propia (commit / rollBack). */
    private function enTransaccion(callable $fn): array
    {
        $db = $this->db();
        $propia = !$db->inTransaction();
        if ($propia) {
            $db->beginTransaction();
        }
        try {
            $resultado = $fn();
            if ($propia) {
                $db->commit();
            }
            return $resultado;
        } catch (\Throwable $e) {
            if ($propia && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private function db(): PDO
    {
        return $this->modelo->getConnection();
    }
}
