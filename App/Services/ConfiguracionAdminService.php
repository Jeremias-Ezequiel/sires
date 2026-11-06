<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Configuracion;
use Exception;

/**
 * Pantalla de configuración (solo administrador): muestra el valor vigente de cada
 * política, los cambios programados y el historial, y registra cambios nuevos.
 *
 * Un cambio siempre es un INSERT con su vigente_desde (ahora o una fecha futura).
 * No se permiten fechas pasadas: reescribirían reglas ya aplicadas.
 */
class ConfiguracionAdminService
{
    /**
     * Qué se puede editar y cómo. 'ambito' explica a qué reservas alcanza el cambio
     * (refleja cómo lee cada regla ConfiguracionService y los EVENTs de la BD):
     * las condiciones que acepta el huésped (seña, cancelación, No-Show, late checkout)
     * se leen a la fecha_alta de la reserva; las operativas, a la fecha actual.
     */
    public const DEFINICIONES = [
        // --- Reservas y precios ---
        'max_noches_reserva' => [
            'grupo' => 'Reservas y precios', 'tipo' => 'int', 'titulo' => 'Máximo de noches por reserva',
            'unidad' => 'noches', 'min' => 1, 'max' => 365,
            'ambito' => 'Reservas que se creen o editen desde la vigencia.',
        ],
        'descuento_ocupacion' => [
            'grupo' => 'Reservas y precios', 'tipo' => 'json', 'titulo' => 'Descuento por ocupación',
            'unidad' => '%', 'min' => 0, 'max' => 100,
            'ambito' => 'Precio de reservas creadas o editadas desde la vigencia. Las existentes conservan su precio pactado.',
        ],
        // --- Pagos ---
        'sena_minima_pct' => [
            'grupo' => 'Pagos', 'tipo' => 'int', 'titulo' => 'Seña mínima para confirmar',
            'unidad' => '% del total', 'min' => 0, 'max' => 100,
            'ambito' => 'Solo reservas creadas desde la vigencia. Las anteriores mantienen la condición con la que se reservaron.',
        ],
        'horas_corte_pago' => [
            'grupo' => 'Pagos', 'tipo' => 'int', 'titulo' => 'Corte de pagos',
            'unidad' => 'horas antes de la entrada', 'min' => 1, 'max' => 720,
            'ambito' => 'Todas las reservas pendientes, en el próximo corte (EVENT corte_pagos).',
        ],
        // --- Cancelación y No-Show ---
        'cancelacion_gratis_horas' => [
            'grupo' => 'Cancelación y No-Show', 'tipo' => 'int', 'titulo' => 'Cancelación sin cargo hasta',
            'unidad' => 'horas antes de la entrada', 'min' => 0, 'max' => 720,
            'ambito' => 'Solo reservas creadas desde la vigencia. Las anteriores mantienen la condición con la que se reservaron.',
        ],
        'cancelacion_penalidad_noches' => [
            'grupo' => 'Cancelación y No-Show', 'tipo' => 'int', 'titulo' => 'Penalidad por cancelar tarde',
            'unidad' => 'noches', 'min' => 0, 'max' => 30,
            'ambito' => 'Solo reservas creadas desde la vigencia. Las anteriores mantienen la condición con la que se reservaron.',
        ],
        'hora_no_show' => [
            'grupo' => 'Cancelación y No-Show', 'tipo' => 'time', 'titulo' => 'No-Show desde las',
            'unidad' => 'hs del día de entrada', 'min' => null, 'max' => null,
            'ambito' => 'No-Show marcados desde la vigencia.',
        ],
        'noshow_noches_retenidas' => [
            'grupo' => 'Cancelación y No-Show', 'tipo' => 'int', 'titulo' => 'Noches retenidas por No-Show',
            'unidad' => 'noches', 'min' => 1, 'max' => 30,
            'ambito' => 'Solo reservas creadas desde la vigencia. Las anteriores mantienen la condición con la que se reservaron. (también en la liberación automática, EVENT liberacion_no_show).',
        ],
        // --- Check-out ---
        'hora_checkout' => [
            'grupo' => 'Check-out', 'tipo' => 'time', 'titulo' => 'Hora de check-out',
            'unidad' => 'hs', 'min' => null, 'max' => null,
            'ambito' => 'Check-outs y cargo de late checkout desde la vigencia (EVENT cargo_late_checkout).',
        ],
        'factor_late_checkout' => [
            'grupo' => 'Check-out', 'tipo' => 'decimal', 'titulo' => 'Cargo por late checkout',
            'unidad' => '% de una noche', 'min' => 0, 'max' => 100,
            'ambito' => 'Solo reservas creadas desde la vigencia. Las anteriores mantienen la condición con la que se reservaron.',
        ],
        // --- Mails al huésped ---
        'recordatorio_dias_antes' => [
            'grupo' => 'Mails al huésped', 'tipo' => 'int', 'titulo' => 'Recordatorio de llegada',
            'unidad' => 'días antes de la entrada', 'min' => 1, 'max' => 30,
            'ambito' => 'Recordatorios que se encolen desde la vigencia (cron/mails.php).',
        ],
        // --- Seguridad ---
        'minutos_token_recuperacion' => [
            'grupo' => 'Seguridad', 'tipo' => 'int', 'titulo' => 'Validez del link de recuperación',
            'unidad' => 'minutos', 'min' => 5, 'max' => 1440,
            'ambito' => 'Links de recuperación de contraseña enviados desde la vigencia.',
        ],
    ];

    private Configuracion $modelo;

    public function __construct()
    {
        $this->modelo = new Configuracion();
    }

    /**
     * Datos de la pantalla agrupados: [grupo => [clave => [...definición, vigente, programados, historial]]].
     */
    public function obtenerPanel(): array
    {
        $ahora = date('Y-m-d H:i:s');
        $porClave = [];
        foreach ($this->modelo->getHistorial() as $fila) {
            $porClave[$fila['clave']][] = $fila;
        }

        $panel = [];
        foreach (self::DEFINICIONES as $clave => $def) {
            $filas = $porClave[$clave] ?? [];
            $programados = array_values(array_filter($filas, fn($f) => $f['vigente_desde'] > $ahora));
            $pasadas = array_values(array_filter($filas, fn($f) => $f['vigente_desde'] <= $ahora));
            $vigente = $pasadas[0] ?? null;

            $panel[$def['grupo']][$clave] = $def + [
                'clave'       => $clave,
                'valor'       => ConfiguracionService::get($clave),   // ya convertido (o el de Politicas)
                'por_defecto' => $vigente === null,
                'vigente'     => $vigente,
                'programados' => array_reverse($programados),          // el más próximo primero
                'historial'   => $pasadas,
            ];
        }
        return $panel;
    }

    /**
     * Registra un cambio. $entrada viene del formulario; $cuando = 'ahora' | 'programar'.
     * @return string mensaje para el usuario
     */
    public function programarCambio(string $clave, array $entrada, string $cuando, string $fecha, int $usuarioId): string
    {
        $def = self::DEFINICIONES[$clave] ?? null;
        if ($def === null) {
            throw new Exception("Política desconocida.");
        }

        $valor = $this->normalizar($clave, $def, $entrada);

        if ($cuando === 'programar') {
            $ts = strtotime($fecha);
            if ($fecha === '' || $ts === false) {
                throw new Exception("Indicá la fecha y hora desde la que rige el cambio.");
            }
            if ($ts <= time()) {
                throw new Exception("La fecha programada tiene que ser futura. Para aplicarlo ya, elegí \"Desde ahora\".");
            }
            $vigenteDesde = date('Y-m-d H:i:00', $ts);
        } else {
            $vigenteDesde = date('Y-m-d H:i:s');
            if ($valor === $this->valorComoTexto($def, ConfiguracionService::get($clave))) {
                throw new Exception("\"{$def['titulo']}\" ya tiene ese valor.");
            }
        }

        $db = $this->modelo->getConnection();
        $db->beginTransaction();
        try {
            $this->modelo->insertar($clave, $valor, $def['tipo'], $def['titulo'], $vigenteDesde, $usuarioId);
            (new AuditoriaService())->registrar('configuracion.cambio', 'configuracion', null,
                ($cuando === 'programar' ? 'Cambio programado: ' : 'Cambio: ') . $def['titulo'], null, [
                    'clave'         => $clave,
                    'valor'         => $valor,
                    'vigente_desde' => $vigenteDesde,
                ]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $cuando === 'programar'
            ? "\"{$def['titulo']}\" cambiará el " . date('d/m/Y \a \l\a\s H:i', strtotime($vigenteDesde)) . "."
            : "\"{$def['titulo']}\" actualizada. Rige desde ahora.";
    }

    /** Valida la entrada del formulario y la devuelve como texto listo para guardar. */
    private function normalizar(string $clave, array $def, array $entrada): string
    {
        $rango = function (float $n) use ($def): void {
            if (($def['min'] !== null && $n < $def['min']) || ($def['max'] !== null && $n > $def['max'])) {
                throw new Exception("\"{$def['titulo']}\" tiene que estar entre {$def['min']} y {$def['max']}.");
            }
        };

        switch ($def['tipo']) {
            case 'int':
                $v = trim((string)($entrada['valor'] ?? ''));
                if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                    throw new Exception("\"{$def['titulo']}\" tiene que ser un número entero.");
                }
                $rango((int)$v);
                return (string)(int)$v;

            case 'time':
                $v = trim((string)($entrada['valor'] ?? ''));
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                    throw new Exception("\"{$def['titulo']}\" tiene que ser una hora HH:MM.");
                }
                return $v;

            case 'decimal':
                // En pantalla es un porcentaje; se guarda como fracción (50 % -> 0.5)
                $v = str_replace(',', '.', trim((string)($entrada['valor'] ?? '')));
                if (!is_numeric($v)) {
                    throw new Exception("\"{$def['titulo']}\" tiene que ser un número.");
                }
                $rango((float)$v);
                return rtrim(rtrim(number_format((float)$v / 100, 4, '.', ''), '0'), '.') ?: '0';

            case 'json':
                // Descuento por ocupación: misma estructura que el valor vigente [capacidad][huéspedes] => %
                $actual = ConfiguracionService::get($clave);
                $enviado = $entrada['tabla'] ?? [];
                $tabla = [];
                foreach ($actual as $capacidad => $porHuespedes) {
                    foreach ($porHuespedes as $huespedes => $_) {
                        $v = $enviado[$capacidad][$huespedes] ?? '';
                        if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                            throw new Exception("Descuento para {$huespedes} huésped(es) en habitación de {$capacidad}: tiene que ser un entero.");
                        }
                        $rango((int)$v);
                        $tabla[(string)$capacidad][(string)$huespedes] = (int)$v;
                    }
                }
                return json_encode($tabla, JSON_THROW_ON_ERROR);
        }
        throw new Exception("Tipo de política no soportado.");
    }

    /** Valor ya convertido -> texto como se guarda (para comparar con lo enviado). */
    private function valorComoTexto(array $def, mixed $valor): string
    {
        return match ($def['tipo']) {
            'json'    => json_encode($valor),
            'decimal' => rtrim(rtrim(number_format((float)$valor, 4, '.', ''), '0'), '.') ?: '0',
            default   => (string)$valor,
        };
    }
}
