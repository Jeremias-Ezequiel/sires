<?php

declare(strict_types=1);

namespace App\Helpers;

class LanguageHelper
{
    private static ?string $detectedLang = null;

    public static function getBrowserLang(): string
    {
        if (self::$detectedLang !== null) {
            return self::$detectedLang;
        }

        $supported = ['es', 'en', 'pt', 'fr', 'de', 'it'];

        $raw = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (empty($raw)) {
            self::$detectedLang = 'es';
            return self::$detectedLang;
        }

        $langs = [];
        foreach (explode(',', $raw) as $part) {
            $pieces = explode(';', trim($part));
            $code = strtolower(trim($pieces[0]));
            $code = substr($code, 0, 2);
            $q = 1.0;
            if (isset($pieces[1]) && str_starts_with($pieces[1], 'q=')) {
                $q = (float) substr($pieces[1], 2);
            }
            $langs[$code] = $q;
        }

        arsort($langs);

        foreach (array_keys($langs) as $code) {
            if (in_array($code, $supported, true)) {
                self::$detectedLang = $code;
                return self::$detectedLang;
            }
        }

        self::$detectedLang = 'es';
        return self::$detectedLang;
    }

    public static function translate(?string $traduccionesJson, string $fallback): string
    {
        if (empty($traduccionesJson)) {
            return $fallback;
        }

        $lang = self::getBrowserLang();

        $data = json_decode($traduccionesJson, true);
        if (!is_array($data)) {
            return $fallback;
        }

        if (isset($data[$lang]) && !empty($data[$lang])) {
            return $data[$lang];
        }

        return $fallback;
    }
}
