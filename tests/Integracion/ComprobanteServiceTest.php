<?php

declare(strict_types=1);

namespace Tests\Integracion;

use App\Dominio\Comprobante;
use App\Services\ComprobanteService;
use App\Services\Mail\Transporte;
use App\Services\MailService;
use App\Services\PagoService;
use App\Services\ReservaService;

/** Comprobantes PDF contra la base real (todo se deshace con ROLLBACK). */
final class ComprobanteServiceTest extends TestIntegracion
{
    /** @return list<array<string, mixed>> */
    private function comprobantes(int $idReserva): array
    {
        $stmt = $this->db->prepare('SELECT * FROM Comprobantes WHERE id_reserva = :id ORDER BY id');
        $stmt->execute([':id' => $idReserva]);
        return $stmt->fetchAll();
    }

    public function testCadaPagoEmiteUnReciboCorrelativo(): void
    {
        $id = $this->crearReserva(self::dentroDe(600), self::dentroDe(603));
        $this->pagar($id, 1000);
        $this->pagar($id, 500);

        $c = $this->comprobantes($id);
        $this->assertCount(2, $c);
        $this->assertSame([Comprobante::PAGO, Comprobante::PAGO], array_column($c, 'tipo'));
        $this->assertSame((int)$c[0]['numero'] + 1, (int)$c[1]['numero']);
        $this->assertEqualsWithDelta(500.0, (float)$c[1]['monto'], 0.001);
        $this->assertNotNull($c[1]['id_transaccion']);

        $datos = json_decode((string)$c[1]['datos'], true);
        $this->assertEqualsWithDelta(1500.0, $datos['pago']['cobrado'], 0.001, 'la copia guarda el saldo después del pago');
        $this->assertArrayHasKey('nombre', $datos['hotel']);
    }

    public function testElReembolsoEmiteSuComprobante(): void
    {
        $id = $this->crearReserva(self::dentroDe(610), self::dentroDe(611));
        $this->pagar($id, 700);
        (new ReservaService())->cancelar($id);
        (new PagoService())->procesarReembolso($id);

        $tipos = array_column($this->comprobantes($id), 'tipo');
        $this->assertSame([Comprobante::PAGO, Comprobante::REEMBOLSO], $tipos);
        $this->assertEqualsWithDelta(700.0, (float)$this->comprobantes($id)[1]['monto'], 0.001);
    }

    public function testCadaResumenTieneNumeroNuevo(): void
    {
        $id = $this->crearReserva(self::dentroDe(620), self::dentroDe(622));
        $servicio = new ComprobanteService();
        $a = $servicio->emitirResumen($id, (int)$_SESSION['user_id']);
        $b = $servicio->emitirResumen($id, (int)$_SESSION['user_id']);
        $this->assertSame((int)$a['numero'] + 1, (int)$b['numero']);
        $this->assertSame(2, (int)$this->valor(
            "SELECT COUNT(*) FROM Auditoria WHERE accion = 'comprobante.emitir' AND id_entidad = :id", [':id' => $id]
        ));
    }

    public function testEmitirDosVecesLaMismaTransaccionDevuelveElMismo(): void
    {
        $id = $this->crearReserva(self::dentroDe(630), self::dentroDe(631));
        $this->pagar($id, 100);
        $tx = (int)$this->comprobantes($id)[0]['id_transaccion'];
        $otra = (new ComprobanteService())->emitirDeTransaccion($tx, null);
        $this->assertSame((int)$this->comprobantes($id)[0]['id'], (int)$otra['id']);
        $this->assertCount(1, $this->comprobantes($id));
    }

    public function testPdfDelReciboYDelResumen(): void
    {
        if (!extension_loaded('iconv')) {
            $this->markTestSkipped('dompdf necesita la extensión iconv.');
        }
        $id = $this->crearReserva(self::dentroDe(640), self::dentroDe(642));
        $this->pagar($id, 250);
        $servicio = new ComprobanteService();

        $recibo = $servicio->pdf($this->comprobantes($id)[0]);
        $this->assertStringStartsWith('%PDF', $recibo['contenido']);
        $this->assertStringStartsWith('recibo-de-pago-', $recibo['nombre']);

        $resumen = $servicio->pdf($servicio->emitirResumen($id, null));
        $this->assertStringStartsWith('%PDF', $resumen['contenido']);
    }

    public function testElMailDelPagoLlevaElReciboAdjunto(): void
    {
        if (!extension_loaded('iconv')) {
            $this->markTestSkipped('dompdf necesita la extensión iconv.');
        }
        $id = $this->crearReserva(self::dentroDe(650), self::dentroDe(651));
        $this->pagar($id, 300);
        $transporte = new class implements Transporte {
            /** @var array<string, list<string>> */
            public array $adjuntos = [];

            public function enviar(string $para, string $asunto, string $html, array $adjuntos = []): void
            {
                $this->adjuntos[$asunto] = array_column($adjuntos, 'nombre');
            }
        };
        (new MailService($transporte))->procesarCola(500);

        $delPago = $transporte->adjuntos['Recibimos tu pago - Reserva #' . $id] ?? null;
        $this->assertNotNull($delPago, 'se mandó el mail del pago');
        $this->assertCount(1, $delPago);
        $this->assertStringStartsWith('recibo-de-pago-', $delPago[0]);
    }
}
