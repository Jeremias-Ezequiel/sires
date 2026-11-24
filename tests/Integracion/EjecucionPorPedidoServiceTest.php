<?php

declare(strict_types=1);

namespace Tests\Integracion;

use App\Services\EjecucionPorPedidoService;

/** Ejecución por pedido de los procesos automáticos contra la base real (requiere la migración 017). */
final class EjecucionPorPedidoServiceTest extends TestIntegracion
{
    /** Registra una corrida OK de cada proceso, $minutos atrás. */
    private function corridasRecientes(int $minutos): void
    {
        $this->db->prepare(
            "INSERT INTO Ejecuciones_Automaticas (proceso, origen, inicio, fin, ok)
             SELECT p.proceso, 'cron', NOW() - INTERVAL :m MINUTE, NOW() - INTERVAL :m2 MINUTE, 1
             FROM (SELECT 'corte_pagos' AS proceso UNION ALL SELECT 'cargo_late_checkout'
                   UNION ALL SELECT 'liberacion_no_show' UNION ALL SELECT 'envio_mails') p"
        )->execute([':m' => $minutos, ':m2' => $minutos]);
    }

    public function testConTodoAlDiaNoEjecutaNada(): void
    {
        $this->corridasRecientes(1);

        $this->assertSame([], (new EjecucionPorPedidoService())->ejecutarVencidos());
    }

    public function testUnProcesoAtrasadoSeEjecutaYQuedaRegistradoComoPedido(): void
    {
        $this->corridasRecientes(1);
        // Late checkout sin correr hace dos horas: vencido aun con el scheduler a cargo (tolerancia 45 min)
        $this->db->prepare(
            "DELETE FROM Ejecuciones_Automaticas WHERE proceso = 'cargo_late_checkout' AND inicio > NOW() - INTERVAL 2 HOUR"
        )->execute();
        $this->db->prepare(
            "INSERT INTO Ejecuciones_Automaticas (proceso, origen, inicio, fin, ok)
             VALUES ('cargo_late_checkout', 'event', NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 2 HOUR, 1)"
        )->execute();

        $ejecutados = (new EjecucionPorPedidoService())->ejecutarVencidos();

        $this->assertSame(['cargo_late_checkout'], $ejecutados);
        $ultima = $this->db->query(
            "SELECT origen, ok FROM Ejecuciones_Automaticas WHERE proceso = 'cargo_late_checkout' ORDER BY id DESC LIMIT 1"
        )->fetch();
        $this->assertSame('pedido', $ultima['origen']);
        $this->assertSame(1, (int)$ultima['ok']);
    }

    public function testUnaCorridaEnCursoNoSeSuperpone(): void
    {
        $this->corridasRecientes(1);
        $this->db->prepare(
            "DELETE FROM Ejecuciones_Automaticas WHERE proceso = 'cargo_late_checkout' AND inicio > NOW() - INTERVAL 2 HOUR"
        )->execute();
        // Empezó hace 5 minutos y no terminó (ok = NULL): por ejemplo, el EVENT corriendo
        $this->db->prepare(
            "INSERT INTO Ejecuciones_Automaticas (proceso, origen, inicio)
             VALUES ('cargo_late_checkout', 'event', NOW() - INTERVAL 5 MINUTE)"
        )->execute();

        $this->assertSame([], (new EjecucionPorPedidoService())->ejecutarVencidos());
    }
}
