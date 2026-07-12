<?php 

namespace App\Helpers;

class UrlHelper
{
    public static function to(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        $disablePrettyUrls = true;

        if($disablePrettyUrls){
            return '/index.php' . $path;
        }

        return $path;
    }

    public static function asset(string $path): string
    {
        return '/sires/public/' . ltrim($path, '/');
    }
}
