<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Auditoria;
use PDO;

/**
 * Registro de auditoría: quién hizo qué, cuándo y qué cambió (tabla Auditoria, migración 013).
 *
 * Uso dentro de un Service, en la misma transacción que la operación:
 *   $antes = $this->auditoria->foto('reserva', $id);   // antes de modificar
 *   ...UPDATE...
 *   $this->auditoria->registrar('reserva.cancelar', 'reserva', $id, 'Cancelación', $antes);
 *   $db->commit();
 * registrar() toma la foto "después" y guarda solo los campos que cambiaron. Usa la misma
 * conexión PDO (singleton), así que si la operación hace rollback el registro también se va.
 *
 * Los procesos automáticos (EVENTs y crons) no se auditan fila por fila: su rastro está en
 * Ejecuciones_Automaticas (MonitoreoService).
 */
class AuditoriaService
{
    /** Campos de cada entidad que se comparan. Nunca contraseñas ni tokens. */
    private const FOTOS = [
        'reserva' => "SELECT r.id_estado_reserva, r.id_habitacion, r.id_cliente, r.fecha_entrada, r.fecha_salida,
                             r.cantidad_huespedes, r.precio_noche_pactado,
                             rp.monto_total, rp.monto_cobrado, rp.saldo_pendiente, rp.monto_retenido, rp.id_estado_pago
                      FROM Reservas r LEFT JOIN Resumen_Pago rp ON rp.id_reserva = r.id
                      WHERE r.id = :id",
        'habitacion' => "SELECT numero, piso, id_tipo_habitacion, id_estado_habitacion, id_motivo_bloqueo,
                                precio_noche_base, is_active, limpieza_urgente
                         FROM Habitaciones WHERE id = :id",
        'empleado' => "SELECT id_rol, nombre, apellido, email, is_active FROM Usuarios WHERE id = :id",
    ];

    /** Entidades con su nombre visible (filtro de la pantalla de auditoría). */
    public const ENTIDADES = [
        'reserva'       => 'Reservas y pagos',
        'habitacion'    => 'Habitaciones',
        'empleado'      => 'Empleados',
        'configuracion' => 'Configuración',
    ];

    /** Nombre visible de cada campo en el detalle de cambios. */
    public const ETIQUETAS = [
        'id_estado_reserva'    => 'Estado',
        'id_habitacion'        => 'Habitación',
        'id_cliente'           => 'Cliente (id)',
        'fecha_entrada'        => 'Entrada',
        'fecha_salida'         => 'Salida',
        'cantidad_huespedes'   => 'Huéspedes',
        'precio_noche_pactado' => 'Precio por noche',
        'monto_total'          => 'Total',
        'monto_cobrado'        => 'Cobrado',
        'saldo_pendiente'      => 'Saldo',
        'monto_retenido'       => 'Retenido',
        'id_estado_pago'       => 'Estado de pago',
        'numero'               => 'Número',
        'piso'                 => 'Piso',
        'id_tipo_habitacion'   => 'Tipo',
        'id_estado_habitacion' => 'Estado',
        'id_motivo_bloqueo'    => 'Motivo de bloqueo',
        'precio_noche_base'    => 'Precio base',
        'is_active'            => 'Activo',
        'limpieza_urgente'     => 'Limpieza urgente',
        'id_rol'               => 'Rol',
        'nombre'               => 'Nombre',
        'apellido'             => 'Apellido',
        'email'                => 'Email',
        'clave'                => 'Política',
        'valor'                => 'Valor',
        'vigente_desde'        => 'Vigente desde',
        'monto'                => 'Monto',
        'id_metodo_pago'       => 'Método de pago (id)',
        'cantidad'             => 'Cantidad',
    ];

    private const CAMPOS_MONEDA = ['precio_noche_pactado', 'monto_total', 'monto_cobrado', 'saldo_pendiente',
                                   'monto_retenido', 'precio_noche_base', 'monto'];
    private const CAMPOS_SI_NO  = ['is_active', 'limpieza_urgente'];

    private Auditoria $modelo;
    private PDO $db;
    private ?array $catalogos = null;

    public function __construct()
    {
        $this->modelo = new Auditoria();
        $this->db = $this->modelo->getConnection();
    }

    /** Estado actual de los campos auditados de una entidad (null si no existe o no tiene foto). */
    public function foto(string $entidad, int $id): ?array
    {
        if (!isset(self::FOTOS[$entidad]) || $id <= 0) {
            return null;
        }
        $stmt = $this->db->prepare(self::FOTOS[$entidad]);
        $stmt->execute([':id' => $id]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ?: null;
    }

    /**
     * Registra una acción. Si $despues es null y la entidad tiene foto, se toma ahora.
     * Con $antes y $despues se guardan solo los campos distintos; si no cambió nada
     * (y había foto previa) igual queda el registro, sin detalle.
     */
    public function registrar(string $accion, string $entidad, ?int $idEntidad, string $descripcion,
                              ?array $antes = null, ?array $despues = null): void
    {
        if ($despues === null && $idEntidad !== null) {
            $despues = $this->foto($entidad, $idEntidad);
        }

        if ($antes !== null && $despues !== null) {
            $cambiosAntes = [];
            $cambiosDespues = [];
            foreach ($despues as $campo => $valor) {
                $previo = $antes[$campo] ?? null;
                if ((string)$previo !== (string)$valor) {
                    $cambiosAntes[$campo] = $previo;
                    $cambiosDespues[$campo] = $valor;
                }
            }
            $antes = $cambiosAntes ?: null;
            $despues = $cambiosDespues ?: null;
        }

        $this->modelo->insertar(
            self::usuarioActual(),
            $accion,
            $entidad,
            $idEntidad,
            $descripcion,
            !empty($antes) ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            !empty($despues) ? json_encode($despues, JSON_UNESCAPED_UNICODE) : null,
            PHP_SAPI === 'cli' ? null : ($_SERVER['REMOTE_ADDR'] ?? null)
        );
    }

    /**
     * Listado para la pantalla de auditoría y los historiales, con los cambios ya legibles.
     * @return array{filas: array, total: int}
     */
    public function listar(array $filtros, int $pagina = 1, int $porPagina = 25): array
    {
        $pagina = max(1, $pagina);
        $resultado = $this->modelo->listar($filtros, $porPagina, ($pagina - 1) * $porPagina);
        $resultado['filas'] = array_map([$this, 'formatearFila'], $resultado['filas']);
        return $resultado;
    }

    /** Últimos registros de una entidad (pestaña Historial de reservas y habitaciones). */
    /** Filas que muestra la pestaña Historial de los detalles; el resto se ve en Auditoría. */
    public const HISTORIAL_LIMITE = 30;

    public function historial(string $entidad, int $idEntidad, int $limite = self::HISTORIAL_LIMITE): array
    {
        return $this->listar(['entidad' => $entidad, 'id_entidad' => $idEntidad], 1, $limite)['filas'];
    }

    public function usuariosConRegistros(): array
    {
        return $this->modelo->usuariosConRegistros();
    }

    /** Agrega 'usuario' (nombre o "Sistema") y 'cambios' [[campo, antes, despues], ...] legibles. */
    private function formatearFila(array $f): array
    {
        $f['usuario'] = $f['id_usuario'] !== null
            ? trim(($f['usuario_nombre'] ?? '') . ' ' . ($f['usuario_apellido'] ?? ''))
            : 'Sistema';

        $antes = $f['antes'] !== null ? (json_decode($f['antes'], true) ?: []) : [];
        $despues = $f['despues'] !== null ? (json_decode($f['despues'], true) ?: []) : [];

        $cambios = [];
        foreach (array_unique(array_merge(array_keys($antes), array_keys($despues))) as $campo) {
            $cambios[] = [
                self::ETIQUETAS[$campo] ?? $campo,
                array_key_exists($campo, $antes) ? $this->valorLegible($campo, $antes[$campo]) : null,
                array_key_exists($campo, $despues) ? $this->valorLegible($campo, $despues[$campo]) : null,
            ];
        }
        $f['cambios'] = $cambios;
        [$f['entidad_texto'], $f['entidad_ruta']] = $this->referencia($f['entidad'], $f['id_entidad'] !== null ? (int)$f['id_entidad'] : null);
        return $f;
    }

    /** Texto y ruta del objeto afectado (la ruta es null si no hay pantalla de detalle). */
    private function referencia(string $entidad, ?int $id): array
    {
        if ($id === null) {
            return [self::ENTIDADES[$entidad] ?? $entidad, $entidad === 'configuracion' ? '/dashboard/settings' : null];
        }
        return match ($entidad) {
            'reserva'    => ['Reserva #' . $id, '/dashboard/booking/detail?id=' . $id],
            'habitacion' => ['Habitación ' . $this->valorLegible('id_habitacion', $id), '/dashboard/rooms/detail?id=' . $id],
            'empleado'   => ['Empleado #' . $id, '/dashboard/employees/edit?id=' . $id],
            default      => [$entidad . ' #' . $id, null],
        };
    }

    private function valorLegible(string $campo, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }
        if (in_array($campo, self::CAMPOS_MONEDA, true)) {
            return '$' . number_format((float)$valor, 2, ',', '.');
        }
        if (in_array($campo, self::CAMPOS_SI_NO, true)) {
            return (int)$valor === 1 ? 'Sí' : 'No';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$valor)) {
            return date('d/m/Y', strtotime((string)$valor));
        }
        $this->catalogos ??= $this->modelo->catalogos();
        if (isset($this->catalogos[$campo])) {
            return $this->catalogos[$campo][(int)$valor] ?? (string)$valor;
        }
        return (string)$valor;
    }

    private static function usuarioActual(): ?int
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $id = $_SESSION['user_id'] ?? null;
        return $id !== null ? (int)$id : null;
    }
}
