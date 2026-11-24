<?php

// SIRES - Bootstrap común de los scripts CLI de cron/
// Carga autoload de Composer, variables de .env y la zona horaria de Politicas.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo ejecutable por CLI.');
}

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

date_default_timezone_set(App\Config\Politicas::ZONA_HORARIA);
