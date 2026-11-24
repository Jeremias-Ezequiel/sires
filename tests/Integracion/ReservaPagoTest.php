<?php

declare(strict_types=1);

namespace Tests\Integracion;

use App\Dominio\Tarifa;
use App\Models\EstadoPago;
use App\Models\Habitacion;
use App\Models\Reserva;
use App\Services\ConfiguracionService;
use App\Services\PagoService;
use App\Services\ReservaService;

/**
 * Flujo completo reserva → pago → confirmación / cancelación → reembolso contra la base real.
 * Todo queda deshecho al final de cada test (ROLLBACK).
 */
final class ReservaPagoTest extends TestIntegracion
{
    /** @return array<string, mixed> */
    private function resumen(int $idReserva): array
    {
        return $this->fila('SELECT * FROM Resumen_Pago WHERE id_reserva = :id', [':id' => $idReserva]);
    }

    public function testCrearReservaGeneraResumenConPrecioPactadoYAuditoria(): void
    {
        $entrada = self::dentroDe(400);
        $salida = self::dentroDe(403);
        $habitacion = $this->habitacionLibre($entrada, $salida);
        $id = $this->crearReserva($entrada, $salida);

        $reserva = $this->fila('SELECT * FROM Reservas WHERE id = :id', [':id' => $id]);
        $esperado = Tarifa::precioEfectivoNoche(
            $habitacion['precio_noche_base'],
            Habitacion::capacidadParaTipo($habitacion['id_tipo_habitacion']),
            1,
            ConfiguracionService::descuentoOcupacion()
        );

        $this->assertSame(Reserva::ESTADO_PENDIENTE, (int)$reserva['id_estado_reserva']);
        $this->assertEqualsWithDelta($esperado, (float)$reserva['precio_noche_pactado'], 0.01);

        $resumen = $this->resumen($id);
        $this->assertSame(EstadoPago::PENDIENTE, (int)$resumen['id_estado_pago']);
        $this->assertEqualsWithDelta($esperado * 3, (float)$resumen['monto_total'], 0.01);
        $this->assertEqualsWithDelta($esperado * 3, (float)$resumen['saldo_pendiente'], 0.01);

        $this->assertSame(1, (int)$this->valor(
            "SELECT COUNT(*) FROM Auditoria WHERE accion = 'reserva.crear' AND entidad = 'reserva' AND id_entidad = :id",
            [':id' => $id]
        ));
    }

    public function testPagoParcialYTotalConfirmaLaReserva(): void
    {
        $id = $this->crearReserva(self::dentroDe(410), self::dentroDe(412));
        $total = (float)$this->resumen($id)['monto_total'];

        $this->pagar($id, round($total / 2, 2));
        $this->assertSame(EstadoPago::PAGO_PARCIAL, (int)$this->resumen($id)['id_estado_pago']);

        $this->pagar($id, (float)$this->resumen($id)['saldo_pendiente']);
        $resumen = $this->resumen($id);
        $this->assertSame(EstadoPago::PAGADO_TOTAL, (int)$resumen['id_estado_pago']);
        $this->assertEqualsWithDelta(0.0, (float)$resumen['saldo_pendiente'], 0.001);
        $this->assertSame(Reserva::ESTADO_CONFIRMADA, (int)$this->valor('SELECT id_estado_reserva FROM Reservas WHERE id = :id', [':id' => $id]));
    }

    public function testPagoMayorAlSaldoSeRechaza(): void
    {
        $id = $this->crearReserva(self::dentroDe(420), self::dentroDe(421));
        $total = (float)$this->resumen($id)['monto_total'];

        $this->expectExceptionMessage('supera el saldo pendiente');
        $this->pagar($id, $total + 1);
    }

    public function testConfirmarSinSenaMinimaFalla(): void
    {
        $id = $this->crearReserva(self::dentroDe(430), self::dentroDe(434));
        $this->pagar($id, 1.0);

        $this->expectExceptionMessage('seña mínima');
        (new ReservaService())->confirmar($id);
    }

    public function testConfirmarConSenaMinima(): void
    {
        $id = $this->crearReserva(self::dentroDe(440), self::dentroDe(442));
        $fechaAlta = (string)$this->valor('SELECT fecha_alta FROM Reservas WHERE id = :id', [':id' => $id]);
        $total = (float)$this->resumen($id)['monto_total'];
        $this->pagar($id, round($total * ConfiguracionService::senaMinimaPct($fechaAlta) / 100, 2) + 0.01);

        (new ReservaService())->confirmar($id);
        $this->assertSame(Reserva::ESTADO_CONFIRMADA, (int)$this->valor('SELECT id_estado_reserva FROM Reservas WHERE id = :id', [':id' => $id]));
    }

    public function testCancelarConAnticipacionNoRetieneNada(): void
    {
        $id = $this->crearReserva(self::dentroDe(450), self::dentroDe(452));
        $this->pagar($id, 1000);

        (new ReservaService())->cancelar($id);

        $resumen = $this->resumen($id);
        $this->assertSame(Reserva::ESTADO_CANCELADA, (int)$this->valor('SELECT id_estado_reserva FROM Reservas WHERE id = :id', [':id' => $id]));
        $this->assertSame(EstadoPago::A_REEMBOLSAB, (int)$resumen['id_estado_pago']);
        $this->assertEqualsWithDelta(0.0, (float)$resumen['monto_retenido'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float)$resumen['monto_total'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float)$resumen['saldo_pendiente'], 0.001);
    }

    public function testCancelarTardeRetieneLaPenalidadYReembolsarDejaRetenido(): void
    {
        // Entrada mañana: siempre dentro del plazo con cargo (salvo una política de 0 h).
        $id = $this->crearReserva(self::dentroDe(1), self::dentroDe(3));
        $reserva = $this->fila('SELECT precio_noche_pactado, fecha_alta FROM Reservas WHERE id = :id', [':id' => $id]);
        $politica = ConfiguracionService::politicaReserva((string)$reserva['fecha_alta']);
        if ($politica->cancelacionGratisHoras === 0) {
            $this->markTestSkipped('La política vigente no cobra cancelaciones.');
        }
        $penalidad = (float)$reserva['precio_noche_pactado'] * min(2, $politica->cancelacionPenalidadNoches);
        $total = (float)$this->resumen($id)['monto_total'];
        $this->pagar($id, $total);

        (new ReservaService())->cancelar($id);
        $resumen = $this->resumen($id);
        $this->assertEqualsWithDelta(min($penalidad, $total), (float)$resumen['monto_retenido'], 0.01);

        if ($penalidad >= $total) {
            $this->assertSame(EstadoPago::RETENIDO, (int)$resumen['id_estado_pago']);
            return;
        }
        $this->assertSame(EstadoPago::A_REEMBOLSAB, (int)$resumen['id_estado_pago']);

        (new PagoService())->procesarReembolso($id);
        $resumen = $this->resumen($id);
        $this->assertSame(EstadoPago::RETENIDO, (int)$resumen['id_estado_pago']);
        $this->assertEqualsWithDelta($penalidad, (float)$resumen['monto_cobrado'], 0.01);
        $this->assertEqualsWithDelta(
            -($total - $penalidad),
            (float)$this->valor('SELECT MIN(monto_abonado) FROM Transacciones_Pago WHERE id_resumen_pago = :r', [':r' => $resumen['id']]),
            0.01,
            'el reembolso se registra como transacción negativa'
        );
        $this->assertSame(1, (int)$this->valor(
            "SELECT COUNT(*) FROM Auditoria WHERE accion = 'pago.reembolso' AND id_entidad = :id",
            [':id' => $id]
        ));
    }

    public function testErrorEnElServiceDeshaceSoloSuParte(): void
    {
        $id = $this->crearReserva(self::dentroDe(460), self::dentroDe(461));
        try {
            $this->pagar($id, 999999999);
            $this->fail('Se esperaba una excepción');
        } catch (\Exception) {
        }
        $this->assertSame(0, (int)$this->valor(
            'SELECT COUNT(*) FROM Transacciones_Pago t JOIN Resumen_Pago r ON r.id = t.id_resumen_pago WHERE r.id_reserva = :id',
            [':id' => $id]
        ));
        $this->assertSame(1, (int)$this->valor('SELECT COUNT(*) FROM Reservas WHERE id = :id', [':id' => $id]), 'la reserva del test sigue visible');
    }
}
