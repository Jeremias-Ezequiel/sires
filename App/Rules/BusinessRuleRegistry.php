<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Habitacion\PisoSoloSimpleRule;

class BusinessRuleRegistry
{
    /**
     * Mapeo de grupos de operación a las reglas que los gobiernan.
     * Al agregar una regla nueva basta con listarla aquí.
     */
    private const GROUPS = [
        'habitacion.alta' => [
            PisoSoloSimpleRule::class,
        ],
    ];

    /**
     * @return class-string<BusinessRule>[] Lista de reglas del grupo
     */
    public static function forGroup(string $group): array
    {
        return self::GROUPS[$group] ?? [];
    }
}