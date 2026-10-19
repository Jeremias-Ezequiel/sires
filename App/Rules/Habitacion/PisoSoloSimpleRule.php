<?php

declare(strict_types=1);

namespace App\Rules\Habitacion;

use App\Models\TipoHabitacion;
use App\Rules\BusinessRule;

class PisoSoloSimpleRule implements BusinessRule
{
    public function supports(array $context): bool
    {
        return isset($context['piso'], $context['id_tipo_habitacion']);
    }

    public function validate(array $context): array
    {
        $piso = (int)$context['piso'];
        $idTipo = (int)$context['id_tipo_habitacion'];

        $pisosRestringidos = (array)(business_rules('habitacion.pisos_solo_simple') ?? []);

        if (!in_array($piso, $pisosRestringidos, true)) {
            return [];
        }

        if ($idTipo !== TipoHabitacion::SIMPLE) {
            return [
                'En el piso ' . $piso . ' solo se pueden registrar habitaciones simples.',
            ];
        }

        return [];
    }
}