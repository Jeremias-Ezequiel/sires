<?php 

namespace App\Helpers;

class UrlHelper
{
    public static function to(string $path): string
    {
        // 1. Separamos la ruta limpia de los parámetros extras (si es que existen)
        $parts = explode('?', $path);
        $purePath = '/' . ltrim($parts[0], '/');
        $extraParams = $parts[1] ?? '';

        // 2. Codificamos únicamente la ruta para el enrutador
        $url = '/sires/index.php?route=' . urlencode($purePath);

        // 3. Si había un "id=...", lo concatenamos al final usando un "&" limpio
        if (!empty($extraParams)) {
            $url .= '&' . $extraParams;
        }

        return $url;
    }

    public static function asset(string $path): string
    {
        return '/sires/public/' . ltrim($path, '/');
    }


    public static function openForm(string $path, string $method = 'POST', string $class = ''): string
    {
        $method = strtoupper($method);
        $classAttr = !empty($class) ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';

        if ($method === 'GET') {
            // Si es GET, apuntamos al index físico e inyectamos el parámetro oculto automáticamente
            $html = '<form action="/sires/index.php" method="GET"' . $classAttr . '>';
            $html .= '<input type="hidden" name="route" value="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '">';
            return $html;
        }

        // Si es POST, usa el ruteo normal que ya teníamos armado
        $action = self::to($path);
        return '<form action="' . $action . '" method="POST"' . $classAttr . '>';
    }
}
