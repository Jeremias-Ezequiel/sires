<?php

declare(strict_types=1);

namespace Tests\Integracion;

use App\Models\ColaMail;
use App\Services\Mail\Transporte;
use App\Services\MailService;

/** Cola de mails contra la base real, con un transporte falso (no se manda nada). */
final class MailServiceTest extends TestIntegracion
{
    /** @return list<array<string, mixed>> */
    private function mails(int $idReserva): array
    {
        $stmt = $this->db->prepare('SELECT * FROM Cola_Mails WHERE id_reserva = :id ORDER BY id');
        $stmt->execute([':id' => $idReserva]);
        return $stmt->fetchAll();
    }

    private static function transporte(bool $falla): Transporte
    {
        return new class ($falla) implements Transporte {
            /** @var list<string> */
            public array $enviados = [];

            public function __construct(private bool $falla)
            {
            }

            public function enviar(string $para, string $asunto, string $html, array $adjuntos = []): void
            {
                if ($this->falla) {
                    throw new \RuntimeException('SMTP caído (prueba)');
                }
                $this->enviados[] = $asunto;
            }
        };
    }

    public function testCrearReservaYPagarEncolanConfirmacionYComprobante(): void
    {
        $id = $this->crearReserva(self::dentroDe(500), self::dentroDe(502));
        $this->pagar($id, 100);

        $mails = $this->mails($id);
        $this->assertSame([ColaMail::TIPO_CONFIRMACION, ColaMail::TIPO_COMPROBANTE_PAGO], array_column($mails, 'tipo'));
        $email = (string)$this->valor('SELECT c.email FROM Reservas r JOIN Clientes c ON c.id = r.id_cliente WHERE r.id = :id', [':id' => $id]);
        $this->assertSame([$email, $email], array_column($mails, 'destinatario'));
        $this->assertStringContainsString('#' . $id, (string)$mails[0]['cuerpo_html']);
        $this->assertStringContainsString('$100,00', (string)$mails[1]['cuerpo_html']);
        $this->assertSame(ColaMail::ESTADO_PENDIENTE, $mails[0]['estado']);
    }

    public function testLaConfirmacionNoSeDuplica(): void
    {
        $id = $this->crearReserva(self::dentroDe(510), self::dentroDe(511));
        $this->assertFalse((new MailService(self::transporte(false)))->encolarConfirmacion($id));
        $this->assertCount(1, $this->mails($id));
    }

    public function testProcesarColaMarcaEnviados(): void
    {
        $id = $this->crearReserva(self::dentroDe(520), self::dentroDe(521));
        $transporte = self::transporte(false);
        $resultado = (new MailService($transporte))->procesarCola(500);

        $this->assertGreaterThanOrEqual(1, $resultado['enviados']);
        $mail = $this->mails($id)[0];
        $this->assertSame(ColaMail::ESTADO_ENVIADO, $mail['estado']);
        $this->assertSame(1, (int)$mail['intentos']);
        $this->assertNotNull($mail['fecha_envio']);
    }

    public function testConfirmacionDeReservaCanceladaSeDescarta(): void
    {
        $id = $this->crearReserva(self::dentroDe(540), self::dentroDe(541));
        (new \App\Services\ReservaService())->cancelar($id);
        (new MailService(self::transporte(false)))->procesarCola(500);

        $mail = $this->mails($id)[0];
        $this->assertSame(ColaMail::ESTADO_ERROR, $mail['estado']);
        $this->assertStringContainsString('se canceló', (string)$mail['ultimo_error']);
        $this->assertNull($mail['fecha_envio']);
    }

    public function testFalloProgramaReintentoYFallaLaCorrida(): void
    {
        $id = $this->crearReserva(self::dentroDe(530), self::dentroDe(531));
        try {
            (new MailService(self::transporte(true)))->procesarCola(500);
            $this->fail('Sin ningún envío correcto, la corrida debe fallar');
        } catch (\Exception $e) {
            $this->assertStringContainsString('SMTP caído', $e->getMessage());
        }
        $mail = $this->mails($id)[0];
        $this->assertSame(ColaMail::ESTADO_PENDIENTE, $mail['estado']);
        $this->assertSame(1, (int)$mail['intentos']);
        $this->assertSame('SMTP caído (prueba)', $mail['ultimo_error']);
        $esperado = time() + 5 * 60;
        $this->assertEqualsWithDelta($esperado, strtotime((string)$mail['proximo_intento']), 120);
    }

    public function testRecordatorioSoloParaReservasHechasAntesDeLaVentana(): void
    {
        $id = $this->crearReserva(self::dentroDe(1), self::dentroDe(2));
        $servicio = new MailService(self::transporte(false));

        // Recién creada: ya tiene la confirmación, no corresponde recordatorio
        $servicio->encolarRecordatorios();
        $this->assertNotContains(ColaMail::TIPO_RECORDATORIO, array_column($this->mails($id), 'tipo'));

        // Hecha hace un mes: sí, y una sola vez
        $this->db->prepare('UPDATE Reservas SET fecha_alta = :f WHERE id = :id')
            ->execute([':f' => date('Y-m-d H:i:s', strtotime('-30 day')), ':id' => $id]);
        $servicio->encolarRecordatorios();
        $servicio->encolarRecordatorios();
        $tipos = array_count_values(array_column($this->mails($id), 'tipo'));
        $this->assertSame(1, $tipos[ColaMail::TIPO_RECORDATORIO] ?? 0);
    }
}
