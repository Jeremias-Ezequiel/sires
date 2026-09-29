<?php

/**
 * Purga el historico que crece sin freno: Auditoria y Login_Intentos.
 *
 * Las dos tablas son append-only desde la app (no hay ninguna pantalla que las
 * borre), asi que sin esto crecen para siempre. Este script las limpia, pero
 * SOLO si alguien lo pide explicitamente: por defecto simula.
 *
 * Por que simula por defecto: un DELETE sin fecha de vuelta atras no tiene
 * vuelta. El que lo corre tiene que ver que filas se van a perder antes de
 * perderlas.
 *
 * Uso:
 *   php scripts/purgar_historico.php                  # simula, no borra nada
 *   php scripts/purgar_historico.php --ejecutar       # borra de verdad
 *   php scripts/purgar_historico.php --ejecutar --meses=36 --dias=120
 *   php scripts/purgar_historico.php --ayuda
 *
 * Valores por defecto: 24 meses de Auditoria (Auditoria::MESES_POR_DEFECTO) y
 * 90 dias de Login_Intentos. Se pueden pisar con --meses y --dias.
 *
 * Para correrlo desde el cron (dejandolo quieto, cada noche):
 *   0 3 * * * cd /ruta/al/sires && /usr/bin/php scripts/purgar_historico.php --ejecutar
 *
 * Requisitos: el usuario de MySQL con el que corre este script necesita DELETE
 * sobre auditoria y login_intentos. Conviene que sea uno distinto del que usa
 * la app web, que solo deberia tener INSERT y SELECT.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se puede correr desde la linea de comandos.\n");
}
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Models\Auditoria;
use App\Models\LoginIntento;

/**
 * Devuelve true si el script fue lanzado para probarlo, no para ejecutarlo.
 */
function soloSimulacion(array $argv): bool
{
    return !in_array('--ejecutar', $argv, true)
        && !in_array('-e', $argv, true);
}

function imprimirAyuda(): void
{
    echo <<<TXT

    Purga el historico de Auditoria y Login_Intentos.

      --meses=N     cuanto tiempo se conserva Auditoria (default 24)
      --dias=N      cuanto tiempo se conservan los intentos de login (default 90)
      --ejecutar    borra de verdad. Sin esto el script solo simula.
      --ayuda       esta ayuda

    Sin --ejecutar no se borra ni una fila: el script cuenta lo que hay y lo que
    se ira, y para. Es a proposito, para no perder auditoria por un typo.


    TXT;
    echo PHP_EOL;
}

/**
 * Cuenta las filas que hay y las que se van.
 *
 * Se cuenta aparte y no se confía en el rowCount() del DELETE: los dos metodos
 * purgarAntiguos() de los modelos se tragan la excepcion y devuelven 0 cuando
 * algo falla, asi que un 0 de ahi no distingue "no habia nada" de "no se pudo
 * borrar" ni de "te faltan permisos".
 *
 * @return array{total:int, aBorrar:int, masViejo:?string}
 */
function contar(string $tabla, string $columnaFecha, int $cantidad, string $unidad): array
{
    $db  = (new Database())->getConnection();
    $sql = "SELECT COUNT(*) AS total,
                   SUM(CASE WHEN $columnaFecha < DATE_SUB(NOW(), INTERVAL $cantidad $unidad)
                            THEN 1 ELSE 0 END) AS a_borrar,
                   MIN($columnaFecha) AS mas_viejo
            FROM $tabla";
    $fila = $db->query($sql)->fetch(PDO::FETCH_ASSOC);

    return [
        'total'     => (int)$fila['total'],
        'aBorrar'   => (int)$fila['a_borrar'],
        'masViejo'  => $fila['mas_viejo'],
    ];
}

function mostrar(string $etiqueta, array $c, string $corte, bool $simulando): void
{
    printf(
        "  %-18s %6d en total, %6d para borrar%s\n",
        $etiqueta,
        $c['total'],
        $c['aBorrar'],
        $c['masViejo'] !== null ? " (la mas vieja es del {$c['masViejo']})" : ''
    );

    if (!$simulando) {
        return;
    }

    if ($c['aBorrar'] === 0) {
        printf("  %-18s no hay nada viejo que purgar.\n", '');
        return;
    }

    $n = $c['aBorrar'];
    printf(
        "  %-18s ^ se va a perder %d fila%s.\n",
        '',
        $n,
        $n === 1 ? '' : 's'
    );
}

$argvLocal = $argv ?? [];

if (in_array('--ayuda', $argvLocal, true) || in_array('-h', $argvLocal, true)) {
    imprimirAyuda();
    exit(0);
}

$meses = Auditoria::MESES_POR_DEFECTO;
$dias  = 90;
$simulando = soloSimulacion($argvLocal);

foreach ($argvLocal as $arg) {
    if (preg_match('/^--meses=(\d+)$/', $arg, $m)) {
        $meses = (int)$m[1];
    } elseif (preg_match('/^--dias=(\d+)$/', $arg, $m)) {
        $dias = (int)$m[1];
    }
}

// Un retention de 0 o negativo borra todo, y eso casi seguro es un --meses= vacio
// o un typo. Mejor frenar que dejarle a alguien la base vacia.
// Sale con 1: si esto corre desde un cron, un 0 aqui seria un fallo silencioso.
if ($meses < 1) {
    fwrite(STDERR, "ERROR: --meses tiene que ser 1 o mas. Con 0 se borra TODA la auditoria.\n");
    exit(1);
}
if ($dias < 1) {
    fwrite(STDERR, "ERROR: --dias tiene que ser 1 o mas. Con 0 se borran TODOS los intentos de login.\n");
    exit(1);
}

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: no se pudo leer el .env: " . $e->getMessage() . "\n");
    exit(1);
}

echo "SIRE - purga de historico" . PHP_EOL;
echo ($simulando
        ? "MODO SIMULACION: no se borra nada. Agregale --ejecutar cuando quieras de verdad."
        : "MODO EJECUCION: se van a borrar filas.")
    . PHP_EOL . PHP_EOL;

try {
    $corteAuditoria = date('Y-m-d', strtotime("-$meses months"));
    $corteLogin     = date('Y-m-d', strtotime("-$dias days"));

    $auditoria = contar('auditoria', 'fecha', $meses, 'MONTH');
    $login     = contar('login_intentos', 'fecha', $dias, 'DAY');

    printf(
        "  Se conserva: Auditoria %s hacia atras, Login_Intentos %s hacia atras\n\n",
        $corteAuditoria,
        $corteLogin
    );

    mostrar('Auditoria', $auditoria, $corteAuditoria, $simulando);
    mostrar('Login_Intentos', $login, $corteLogin, $simulando);
    echo PHP_EOL;

    if ($simulando) {
        if ($auditoria['aBorrar'] === 0 && $login['aBorrar'] === 0) {
            echo "No hay nada que purgar." . PHP_EOL;
        } else {
            echo "Nada se borro. Si estas de acuerdo, corrélo con --ejecutar." . PHP_EOL;
        }
        exit(0);
    }

    if ($auditoria['aBorrar'] === 0 && $login['aBorrar'] === 0) {
        echo "No hay nada viejo que purgar. Se sale sin tocar la base." . PHP_EOL;
        exit(0);
    }

    $auditoriaModel = new Auditoria();
    $loginModel     = new LoginIntento();

    $borradasAuditoria = $auditoriaModel->purgarAntiguos($meses);
    $borradasLogin     = $loginModel->purgarAntiguos($dias);

    // Se vuelve a contar y se compara con lo que se calculo antes. Si no
    // cuadra, el metodo del modelo se comio una excepcion (no tiene permisos,
    // la tabla no existe, se cayo la conexion) y devuelve 0 como si fuera
    // todo normal. Sin esta comprobacion, un cron fallido pareceria exitoso.
    $auditoriaDespues = contar('auditoria', 'fecha', $meses, 'MONTH');
    $loginDespues     = contar('login_intentos', 'fecha', $dias, 'DAY');

    $esperadoAuditoria = $auditoria['aBorrar'];
    $esperadoLogin     = $login['aBorrar'];
    $realAuditoria     = $esperadoAuditoria - $auditoriaDespues['aBorrar'];
    $realLogin         = $esperadoLogin - $loginDespues['aBorrar'];

    echo "  Borradas: " . $realAuditoria . " de auditoria, " . $realLogin . " de intentos de login."
        . PHP_EOL . PHP_EOL;

    $fallo = false;
    foreach ([
        ['Auditoria', $esperadoAuditoria, $realAuditoria],
        ['Login_Intentos', $esperadoLogin, $realLogin],
    ] as [$nombre, $esperado, $real]) {
        if ($esperado !== $real) {
            $fallo = true;
            printf(
                "  FALLO en %s: se esperaban %d y se borraron %d. Revisar permisos (DELETE) y el log de PHP.\n",
                $nombre,
                $esperado,
                $real
            );
        }
    }

    if ($fallo) {
        exit(1);
    }

    echo "Listo." . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo PHP_EOL . "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
