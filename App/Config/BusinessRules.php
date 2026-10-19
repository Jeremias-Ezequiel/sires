<?php

declare(strict_types=1);

// =============================================================================
// REGLAS Y VARIABLES DE NEGOCIO CENTRALIZADAS
// -----------------------------------------------------------------------------
// Este archivo concentra las variables que afectan el comportamiento de los
// métodos del sistema. Las reglas concretas (App/Rules/) leen estos valores,
// de modo que ajustar el negocio = editar esta configuración sin tocar lógica.
//
// Ejemplo: 'pisos_solo_simple' define en qué pisos SOLO se pueden dar de alta
// habitaciones de tipo "Simple". Agregar un número aquí extiende la restricción
// a ese piso automáticamente.
// =============================================================================

return [
    'habitacion' => [
        // Pisos donde únicamente se permite registrar habitaciones simples.
        'pisos_solo_simple' => [2],
    ],
];