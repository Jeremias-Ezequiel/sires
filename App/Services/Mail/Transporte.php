<?php

declare(strict_types=1);

namespace App\Services\Mail;

/** Envío de un mail. Lanza una excepción si no se pudo enviar. */
interface Transporte
{
    /** @param list<array{nombre: string, contenido: string, tipo: string}> $adjuntos */
    public function enviar(string $para, string $asunto, string $html, array $adjuntos = []): void;
}
