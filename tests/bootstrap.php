<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Misma zona horaria que index.php y cron/bootstrap.php: las reglas usan date() y strtotime().
date_default_timezone_set(\App\Config\Politicas::ZONA_HORARIA);
