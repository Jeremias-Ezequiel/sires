<?php

declare(strict_types=1);

namespace App\Core;

class CsrfHelper
{
    public static function getToken(): string
    {
        return csrf_token();
    }

    public static function getHiddenInput(): string
    {
        return csrf_field();
    }

    public static function validate(): bool
    {
        try {
            csrf_check();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}