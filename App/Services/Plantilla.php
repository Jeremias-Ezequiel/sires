<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

/**
 * Render de plantillas .phtml a texto (mails y comprobantes PDF).
 * Suma a los datos los helpers $e (escape), $dinero y $fecha.
 */
final class Plantilla
{
    /** @param array<string, mixed> $datos */
    public static function render(string $archivo, array $datos): string
    {
        $datos += self::helpers();
        extract($datos, EXTR_SKIP);
        ob_start();
        try {
            require $archivo;
            return (string)ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /** @return array<string, callable> */
    public static function helpers(): array
    {
        return [
            'e'      => fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'),
            'dinero' => fn (mixed $v): string => '$' . number_format((float)$v, 2, ',', '.'),
            'fecha'  => fn (string $f): string => date('d/m/Y', strtotime($f)),
        ];
    }
}
