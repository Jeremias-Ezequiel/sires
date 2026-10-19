<?php

declare(strict_types=1);

namespace App\Rules;

interface BusinessRule
{
    /**
     * Indica si la regla debe evaluarse para este contexto de operación.
     *
     * @param array<string, mixed> $context Datos de la operación (piso, tipo, precio, etc.)
     */
    public function supports(array $context): bool;

    /**
     * Valida la regla contra el contexto.
     *
     * @param array<string, mixed> $context Datos de la operación
     * @return string[] Lista de mensajes de error (vacía = regla cumplida)
     */
    public function validate(array $context): array;
}