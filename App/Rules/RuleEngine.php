<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\BusinessRuleException;

class RuleEngine
{
    /**
     * Ejecuta todas las reglas registradas para un grupo de operación.
     *
     * @param array<string, mixed> $context Datos de la operación
     * @throws BusinessRuleException Si alguna regla del grupo resulta violada
     */
    public static function validate(string $group, array $context): void
    {
        $failures = [];

        foreach (BusinessRuleRegistry::forGroup($group) as $ruleClass) {
            /** @var BusinessRule $rule */
            $rule = new $ruleClass();

            if (!$rule->supports($context)) {
                continue;
            }

            foreach ($rule->validate($context) as $message) {
                $failures[] = $message;
            }
        }

        if ($failures !== []) {
            throw new BusinessRuleException(implode(' ', $failures));
        }
    }
}