<?php

declare(strict_types=1);

namespace Tests\Unidad;

use App\Dominio\ReglasMail;
use PHPUnit\Framework\TestCase;

final class ReglasMailTest extends TestCase
{
    public function testEsperaCrecienteEntreIntentos(): void
    {
        $t = 1_000_000;
        $this->assertSame($t + 5 * 60, ReglasMail::proximoIntento(1, $t));
        $this->assertSame($t + 15 * 60, ReglasMail::proximoIntento(2, $t));
        $this->assertSame($t + 60 * 60, ReglasMail::proximoIntento(3, $t));
        $this->assertSame($t + 240 * 60, ReglasMail::proximoIntento(4, $t));
    }

    public function testDespuesDelUltimoIntentoNoSeReintenta(): void
    {
        $this->assertNull(ReglasMail::proximoIntento(ReglasMail::MAX_INTENTOS, 0));
        $this->assertNull(ReglasMail::proximoIntento(9, 0));
    }

    public function testVentanaDelRecordatorio(): void
    {
        $this->assertSame(['desde' => '2026-11-07', 'hasta' => '2026-11-09'], ReglasMail::ventanaRecordatorio('2026-11-06', 3));
        $this->assertSame(['desde' => '2026-12-01', 'hasta' => '2026-12-01'], ReglasMail::ventanaRecordatorio('2026-11-30', 1));
        $this->assertSame(['desde' => '2026-11-07', 'hasta' => '2026-11-07'], ReglasMail::ventanaRecordatorio('2026-11-06', 0), 'mínimo 1 día');
    }

    public function testSoloSeRecuerdaSiSeReservoAntesDeLaVentana(): void
    {
        // Entrada el 10 con 3 días de aviso: la ventana empieza el 07
        $this->assertTrue(ReglasMail::correspondeRecordatorio('2026-11-01 15:00:00', '2026-11-10', 3));
        $this->assertTrue(ReglasMail::correspondeRecordatorio('2026-11-06 23:59:59', '2026-11-10', 3));
        $this->assertFalse(ReglasMail::correspondeRecordatorio('2026-11-07 08:00:00', '2026-11-10', 3));
        $this->assertFalse(ReglasMail::correspondeRecordatorio('2026-11-09 08:00:00', '2026-11-10', 3));
    }

    public function testDescarteDeMailsDeReservasCanceladas(): void
    {
        $cancelada = \App\Models\EstadoReserva::CANCELADA;
        $this->assertNotNull(ReglasMail::motivoDescarte('confirmacion', $cancelada));
        $this->assertNotNull(ReglasMail::motivoDescarte('recordatorio', $cancelada));
        $this->assertNull(ReglasMail::motivoDescarte('comprobante_pago', $cancelada));
        $this->assertNull(ReglasMail::motivoDescarte('confirmacion', \App\Models\EstadoReserva::CONFIRMADA));
        $this->assertNull(ReglasMail::motivoDescarte('confirmacion', null));
    }

    public function testClavesUnicas(): void
    {
        $this->assertSame('confirmacion:15', ReglasMail::claveConfirmacion(15));
        $this->assertSame('recordatorio:15:2026-12-01', ReglasMail::claveRecordatorio(15, '2026-12-01'));
        $this->assertNotSame(ReglasMail::claveRecordatorio(15, '2026-12-01'), ReglasMail::claveRecordatorio(15, '2026-12-02'));
        $this->assertSame('pago:99', ReglasMail::claveComprobantePago(99));
    }

    public function testErrorRecortado(): void
    {
        $this->assertSame('SMTP Error: could not connect', ReglasMail::recortarError("SMTP Error:\n  could not   connect "));
        $this->assertSame(ReglasMail::LARGO_ERROR, mb_strlen(ReglasMail::recortarError(str_repeat('á', 900))));
    }
}
