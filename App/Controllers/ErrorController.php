<?php

namespace App\Controllers;

class ErrorController
{
    public function notFound(): void
    {
        require_once __DIR__ . '/../views/errors/404.phtml';
    }

    public function methodNotAllowed(): void
    {
        require_once __DIR__ . '/../views/errors/405.phtml';
    }

    public function internalServerError(): void
    {
        require_once __DIR__ . '/../views/errors/500.phtml';
    }
}
