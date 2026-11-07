<?php

declare(strict_types=1);

namespace Tests\Integracion;

use App\Dominio\ReglasPago;
use App\Models\EstadoPago;
use App\Models\Reserva;
use App\Services\ConfiguracionService;
use App\Services\PagoService;
use App\Services\ReservaService;

/**
 * PagoService contra la base real: retención, reembolso, corte de pagos y late checkout.
 * Los procesos automáticos recorren toda la base, pero todo queda deshecho al final (ROLLBACK).
 */
final class PagoServiceTest extends TestIntegracion
{
    /** @return array<string, mixed> */
    private function resumen(int $idReserva): array
    {
        return $this->fila('SELECT * FROM Resumen_Pago WHERE id_reserva = :id', [':id' => $idReserva]);
    }

    private function estadoReserva(int $idReserva): int
    {
        return (int)$this->valor('SELECT id_estado_reserva FROM Reservas WHERE id = :id', [':id' => $idReserva]);
    }

    // ------------------------------------------------------------------
    // Retención
    // ------------------------------------------------------------------

    public function testRetencionSinCobroNoHaceNada(): void
    {
        $id = $this->crearReserva(self::dentroDe(500), self::dentroDe(502));

        $this->assertNull((new PagoService())->aplicarRetencion($id, 500.0));
        $this->assertSame(EstadoPago::PENDIENTE, (int)$this->resumen($id)['id_estado_pago']);
    }

    public function testRetencionMenorAlCobroQuedaAReembolsar(): void
    {
        $id = $this->crearReserva(self::dentroDe(505), self::dentroDe(507));
        $this->pagar($id, 1000.0);

        $estado = (new PagoService())->aplicarRetencion($id, 300.0);

        $resumen = $this->resumen($id);
        $this->assertSame(EstadoPago::A_REEMBOLSAB, $estado);
        $this->assertSame(EstadoPago::A_REEMBOLSAB, (int)$resumen['id_estado_pago']);
        $this->assertEqualsWithDelta(300.0, (float)$resumen['monto_retenido'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float)$resumen['monto_total'], 0.001, 'el total pasa a ser lo cobrado');
        $this->assertEqualsWithDelta(0.0, (float)$resumen['saldo_pendiente'], 0.001);
    }

    public function testRetencionMayorAlCobroSeLimitaYQuedaRetenido(): void
    {
        $id = $this->crearReserva(self::dentroDe(510), self::dentroDe(512));
        $this->pagar($id, 200.0);

        $estado = (new PagoService())->aplicarRetencion($id, 900.0);

        $this->assertSame(EstadoPago::RETENIDO, $estado);
        $this->assertEqualsWithDelta(200.0, (float)$this->resumen($id)['monto_retenido'], 0.001);
    }

    // ------------------------------------------------------------------
    // Reembolso
    // ------------------------------------------------------------------

    public function testReembolsoTotalDejaReembolsadoYEmiteTransaccionNegativa(): void
    {
        $id = $this->crearReserva(self::dentroDe(515), self::dentroDe(517));
        $this->pagar($id, 1000.0);
        (new ReservaService())->cancelar($id);
        $this->assertSame(EstadoPago::A_REEMBOLSAB, (int)$this->resumen($id)['id_estado_pago']);

        (new PagoService())->procesarReembolso($id);

        $resumen = $this->resumen($id);
        $this->assertSame(EstadoPago::REEMBOLSADO, (int)$resumen['id_estado_pago']);
        $this->assertEqualsWithDelta(0.0, (float)$resumen['monto_cobrado'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float)$resumen['monto_total'], 0.001);
        $this->assertEqualsWithDelta(-1000.0, (float)$this->valor(
            'SELECT MIN(monto_abonado) FROM Transacciones_Pago WHERE id_resumen_pago = :r',
            [':r' => $resumen['id']]
        ), 0.001);
    }

    public function testReembolsarDosVecesFalla(): void
    {
        $id = $this->crearReserva(self::dentroDe(520), self::dentroDe(522));
        $this->pagar($id, 1000.0);
        (new ReservaService())->cancelar($id);
        (new PagoService())->procesarReembolso($id);

        $this->expectExceptionMessage('no está pendiente de reembolso');
        (new PagoService())->procesarReembolso($id);
    }

    public function testNoSeReembolsaUnaReservaActiva(): void
    {
        $id = $this->crearReserva(self::dentroDe(525), self::dentroDe(527));
        $this->pagar($id, 1000.0);

        $this->expectExceptionMessage('no está pendiente de reembolso');
        (new PagoService())->procesarReembolso($id);
    }

    // ------------------------------------------------------------------
    // Corte de pagos (respaldo del EVENT corte_pagos)
    // ------------------------------------------------------------------

    /** Fecha de entrada que el corte de pagos procesa hoy. */
    private static function fechaDeCorte(): string
    {
        return date('Y-m-d', time() + ConfiguracionService::horasCortePago() * 3600);
    }

    private function reservaEnFechaDeCorte(): int
    {
        $entrada = self::fechaDeCorte();
        if ($entrada <= date('Y-m-d')) {
            $this->markTestSkipped('El corte de pagos vigente es menor a un día.');
        }
        try {
            return $this->crearReserva($entrada, date('Y-m-d', strtotime($entrada . ' +2 day')));
        } catch (\Exception $e) {
            $this->markTestSkipped('No se pudo crear la reserva en la fecha de corte: ' . $e->getMessage());
        }
    }

    public function testCorteCancelaLaPendienteConSenaYLaRetiene(): void
    {
        $id = $this->reservaEnFechaDeCorte();
        $this->pagar($id, 100.0);

        $resultado = (new PagoService())->ejecutarCortePagos();

        $resumen = $this->resumen($id);
        $this->assertGreaterThanOrEqual(1, $resultado['procesadas']);
        $this->assertSame(Reserva::ESTADO_CANCELADA, $this->estadoReserva($id));
        $this->assertSame(EstadoPago::RETENIDO, (int)$resumen['id_estado_pago']);
        $this->assertEqualsWithDelta(100.0, (float)$resumen['monto_retenido'], 0.001);
        $this->assertStringContainsString('Seña retenida.', (string)$this->valor(
            'SELECT observaciones FROM Reservas WHERE id = :id', [':id' => $id]
        ));
    }

    public function testCorteNoTocaLasReservasPagadas(): void
    {
        $id = $this->reservaEnFechaDeCorte();
        $this->pagar($id, (float)$this->resumen($id)['saldo_pendiente']);
        $estadoAntes = $this->estadoReserva($id);

        (new PagoService())->ejecutarCortePagos();

        $this->assertSame($estadoAntes, $this->estadoReserva($id));
        $this->assertSame(EstadoPago::PAGADO_TOTAL, (int)$this->resumen($id)['id_estado_pago']);
    }

    public function testCorteNoTocaReservasDeOtraFecha(): void
    {
        $id = $this->crearReserva(self::dentroDe(530), self::dentroDe(532));
        $this->pagar($id, 100.0);

        (new PagoService())->ejecutarCortePagos();

        $this->assertSame(Reserva::ESTADO_PENDIENTE, $this->estadoReserva($id));
    }

    // ------------------------------------------------------------------
    // Late checkout (respaldo del EVENT cargo_late_checkout)
    // ------------------------------------------------------------------

    public function testLateCheckoutSeCobraUnaSolaVez(): void
    {
        if (date('H:i:s') < ConfiguracionService::horaCheckoutSql()) {
            $this->markTestSkipped('Todavía no pasó la hora de checkout: correr después de las ' . ConfiguracionService::horaCheckout() . ' hs.');
        }
        $id = $this->crearReserva(self::dentroDe(540), self::dentroDe(542));
        // Se lleva la reserva a "en casa con salida hoy" directo en la base: crear() no acepta fechas pasadas.
        try {
            $this->db->prepare(
                'UPDATE Reservas SET fecha_entrada = :entrada, fecha_salida = :salida, id_estado_reserva = :encasa WHERE id = :id'
            )->execute([
                ':entrada' => date('Y-m-d', strtotime('-1 day')),
                ':salida'  => date('Y-m-d'),
                ':encasa'  => Reserva::ESTADO_EN_CASA,
                ':id'      => $id,
            ]);
        } catch (\PDOException $e) {
            $this->markTestSkipped('La habitación está ocupada hoy (trigger de solapamiento): ' . $e->getMessage());
        }
        $reserva = $this->fila('SELECT precio_noche_pactado, fecha_alta FROM Reservas WHERE id = :id', [':id' => $id]);
        $fee = ReglasPago::lateFee((float)$reserva['precio_noche_pactado'], ConfiguracionService::factorLateCheckout((string)$reserva['fecha_alta']));
        if ($fee <= 0) {
            $this->markTestSkipped('El factor de late checkout vigente es 0.');
        }
        $totalAntes = (float)$this->resumen($id)['monto_total'];

        (new PagoService())->aplicarLateCheckout();
        (new PagoService())->aplicarLateCheckout();

        $this->assertEqualsWithDelta($totalAntes + $fee, (float)$this->resumen($id)['monto_total'], 0.01);
        $this->assertSame(1, (int)$this->valor('SELECT late_fee_aplicado FROM Reservas WHERE id = :id', [':id' => $id]));
    }
}
