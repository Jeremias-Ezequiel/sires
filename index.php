<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use FastRoute\Dispatcher;
use function FastRoute\simpleDispatcher;
use App\Middleware\AuthMiddleware;
use App\Core\ErrorHandler;

ErrorHandler::register();

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    if (isset($_GET['route'])){
        $route = $_GET['route'];
    }else{
        $route = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        $route = str_replace(['/index.php', '/sires'], '', $route);
    }

    if(empty($route) || $route === ''){
        $route = '/';
    }

    // 1. IMPORTAMOS TU ARCHIVO DE RUTAS EXTERNO
    $dispatcher = simpleDispatcher(require __DIR__ . '/App/routes.php');

    $httpMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // 2. EVALUAMOS LA PETICIÓN
    $routeInfo = $dispatcher->dispatch($httpMethod, $route);

    switch ($routeInfo[0]) {
        case Dispatcher::NOT_FOUND:
            http_response_code(404);
            $controllerClass = App\Controllers\ErrorController::class;
            $controller = new $controllerClass();
            $controller->notFound();
            exit;

        case Dispatcher::METHOD_NOT_ALLOWED:
            http_response_code(405);
            $controllerClass = App\Controllers\ErrorController::class;
            $controller = new $controllerClass();
            $controller->methodNotAllowed();
            exit;

        case Dispatcher::FOUND:
            $routeData = $routeInfo[1];
            $vars = $routeInfo[2];

            foreach ($_GET as $key => $value) {
                if ($key !== 'route') {
                    $vars[$key] = $value;
                }
            }

            if (!empty($routeData['middlewares']) && is_array($routeData['middlewares'])) {
                foreach ($routeData['middlewares'] as $middlewareInfo) {
                    if (is_array($middlewareInfo) && isset($middlewareInfo[0], $middlewareInfo[1])) {
                        $mClass = $middlewareInfo[0];
                        $mMethod = $middlewareInfo[1];

                        $mClass::$mMethod();
                    }
                }
            }

            if (isset($routeData['roles']) && is_array($routeData['roles'])) {
                App\Middleware\AuthMiddleware::authorize($routeData['roles']);
            }

            $handler = $routeData['action'];
            $controllerClass = $handler[0];
            $method = $handler[1];

            $controller = new $controllerClass();
            $controller->$method($vars);
            break;
    }
} catch (\Throwable $e) {
    ErrorHandler::handleException($e);
    exit;
}
