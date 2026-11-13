<?php

declare(strict_types=1);

namespace Tests\Unidad;

use App\Dominio\ReglasEjecucion;
use PHPUnit\Framework\TestCase;

final class ReglasEjecucionTest extends TestCase
{
    private const CADA_15 = ['cada' => 15];
    private const DIARIO_8 = ['diario' => '08:00'];

    private static function t(string $fecha): int
    {
        return (int)strtotime($fecha);
    }

    public function testProcesoQueNuncaCorrioEstaVencido(): void
    {
        $this->assertTrue(ReglasEjecucion::vencido(self::CADA_15, null, null, null, self::t('2026-11-13 10:00')));
    }

    public function testPeriodicoVenceAlCumplirseElIntervalo(): void
    {
        $ultima = self::t('2026-11-13 10:00');
        $this->assertFalse(ReglasEjecucion::vencido(self::CADA_15, $ultima, true, $ultima, self::t('2026-11-13 10:14:59')));
        $this->assertTrue(ReglasEjecucion::vencido(self::CADA_15, $ultima, true, $ultima, self::t('2026-11-13 10:15')));
    }

    public function testUnaCorridaFallidaNoSeReintentaEnCadaPedido(): void
    {
        // Última OK hace una hora, última (fallida) hace 5 minutos: se espera el intervalo
        $ok = self::t('2026-11-13 09:00');
        $fallida = self::t('2026-11-13 09:55');
        $this->assertFalse(ReglasEjecucion::vencido(self::CADA_15, $fallida, true, $ok, self::t('2026-11-13 10:00')));
        $this->assertTrue(ReglasEjecucion::vencido(self::CADA_15, $fallida, true, $ok, self::t('2026-11-13 10:10')));
    }

    public function testUnaCorridaEnCursoNoSeSuperpone(): void
    {
        // El EVENT (o otro pedido) empezó hace 20 minutos y no terminó
        $inicio = self::t('2026-11-13 09:40');
        $this->assertFalse(ReglasEjecucion::vencido(self::CADA_15, $inicio, false, null, self::t('2026-11-13 10:00')));
    }

    public function testUnaCorridaColgadaNoBloqueaParaSiempre(): void
    {
        $inicio = self::t('2026-11-13 08:00');
        $ahora = $inicio + ReglasEjecucion::MINUTOS_CORRIDA_COLGADA * 60;
        $this->assertTrue(ReglasEjecucion::vencido(self::CADA_15, $inicio, false, null, $ahora));
    }

    public function testDiarioNoCorreAntesDeLaHora(): void
    {
        $ayer = self::t('2026-11-12 08:00');
        $this->assertFalse(ReglasEjecucion::vencido(self::DIARIO_8, $ayer, true, $ayer, self::t('2026-11-13 07:59')));
        $this->assertFalse(ReglasEjecucion::vencido(self::DIARIO_8, null, null, null, self::t('2026-11-13 07:59')));
    }

    public function testDiarioCorreUnaVezPasadaLaHora(): void
    {
        $ayer = self::t('2026-11-12 08:00');
        $this->assertTrue(ReglasEjecucion::vencido(self::DIARIO_8, $ayer, true, $ayer, self::t('2026-11-13 08:00')));
        // Llegó tarde (nadie entró a la mañana): corre igual a la tarde
        $this->assertTrue(ReglasEjecucion::vencido(self::DIARIO_8, $ayer, true, $ayer, self::t('2026-11-13 18:30')));

        $hoy = self::t('2026-11-13 09:12');
        $this->assertFalse(ReglasEjecucion::vencido(self::DIARIO_8, $hoy, true, $hoy, self::t('2026-11-13 18:30')));
    }

    public function testDiarioFallidoSeReintentaConEspera(): void
    {
        $ayer = self::t('2026-11-12 08:00');
        $fallida = self::t('2026-11-13 08:00');
        $this->assertFalse(ReglasEjecucion::vencido(self::DIARIO_8, $fallida, true, $ayer, self::t('2026-11-13 08:10')));
        $this->assertTrue(ReglasEjecucion::vencido(self::DIARIO_8, $fallida, true, $ayer, self::t('2026-11-13 08:15')));
    }

    public function testConSchedulerSoloActuaPasadaLaTolerancia(): void
    {
        $ok = self::t('2026-11-13 09:00');
        $this->assertFalse(ReglasEjecucion::superaTolerancia($ok, true, $ok, 45, self::t('2026-11-13 09:45')));
        $this->assertTrue(ReglasEjecucion::superaTolerancia($ok, true, $ok, 45, self::t('2026-11-13 09:45:01')));
        $this->assertTrue(ReglasEjecucion::superaTolerancia(null, null, null, 45, self::t('2026-11-13 09:00')));
        // En curso: no se superpone aunque la última OK sea vieja
        $this->assertFalse(ReglasEjecucion::superaTolerancia(self::t('2026-11-13 09:50'), false, $ok, 45, self::t('2026-11-13 10:00')));
    }

    public function testLaProgramacionCubreLosProcesosMonitoreados(): void
    {
        $this->assertSame(
            array_keys(\App\Services\MonitoreoService::PROCESOS),
            array_keys(ReglasEjecucion::PROGRAMACION)
        );
    }
}
