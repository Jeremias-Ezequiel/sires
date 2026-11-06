<?php

declare(strict_types=1);

namespace Tests\Integracion;

use App\Services\ConfiguracionService;

/** Lectura de Configuracion versionada contra la base real (las filas de prueba se deshacen). */
final class ConfiguracionServiceTest extends TestIntegracion
{
    public function testPoliticaSeLeeALaFechaDeAltaDeLaReserva(): void
    {
        $this->db->prepare(
            "INSERT INTO Configuracion (clave, valor, tipo, descripcion, vigente_desde)
             VALUES ('sena_minima_pct', '77', 'int', 'test de integración', '2099-01-01 00:00:00'),
                    ('cancelacion_gratis_horas', '5', 'int', 'test de integración', '2099-01-01 00:00:00')"
        )->execute();

        $antes = ConfiguracionService::politicaReserva('2098-12-31 23:59:59');
        $despues = ConfiguracionService::politicaReserva('2099-01-01 00:00:00');

        $this->assertNotSame(77.0, $antes->senaMinimaPct);
        $this->assertSame(77.0, $despues->senaMinimaPct);
        $this->assertSame(5, $despues->cancelacionGratisHoras);
        $this->assertSame($antes->noShowNochesRetenidas, $despues->noShowNochesRetenidas, 'las claves sin cambios siguen igual');
    }

    public function testTodasLasClavesSeLeenSinError(): void
    {
        $this->assertGreaterThanOrEqual(0, ConfiguracionService::horasCortePago());
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', ConfiguracionService::horaCheckout());
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', ConfiguracionService::horaNoShow());
        $this->assertGreaterThan(0, ConfiguracionService::maxNochesReserva());
        $this->assertGreaterThan(0, ConfiguracionService::minutosTokenRecuperacion());
        $this->assertNotEmpty(ConfiguracionService::descuentoOcupacion());
    }
}
