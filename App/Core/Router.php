<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, array<int, array{0:string, 1:array|callable, 2:array<int,class-string>}>> */
    private array $routes = [];

    public function __construct(array $routes)
    {
        foreach ($routes as $route) {
            $method      = strtoupper((string) $route[0]);
            $path        = (string) $route[1];
            $handler     = $route[2];
            $middlewares = $route[3] ?? [];
            $this->routes[$method][] = [$this->compile($path), $handler, $middlewares];
        }
    }

    public function dispatch(Request $request): Response
    {
        $candidates = $this->routes[$request->method] ?? [];
        foreach ($candidates as [$pattern, $handler, $middlewares]) {
            if (preg_match($pattern, $request->path, $matches)) {
                $params = array_filter(
                    $matches,
                    static fn($key) => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );
                $blocked = $this->runMiddlewares($middlewares, $request);
                if ($blocked instanceof Response) return $blocked;
                return $this->call($handler, $request, $params);
            }
        }

        throw new HttpException(404, "Rota não encontrada: {$request->method} {$request->path}");
    }

    private function compile(string $path): string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') $path = '/';
        $regex = preg_replace(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            '(?P<$1>[^/]+)',
            $path
        );
        return '#^' . $regex . '$#';
    }

    private function call(array|callable $handler, Request $request, array $params): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            if (!class_exists($class)) {
                throw new HttpException(500, "Controller inexistente: {$class}");
            }
            $instance = new $class();
            if (!method_exists($instance, $method)) {
                throw new HttpException(500, "Método inexistente: {$class}::{$method}");
            }
            $result = $instance->{$method}($request, ...array_values($params));
        } else {
            $result = $handler($request, ...array_values($params));
        }

        return $this->normalize($result);
    }

    private function normalize(mixed $result): Response
    {
        if ($result instanceof Response)         return $result;
        if (is_string($result))                  return Response::html($result);
        if (is_array($result) || is_object($result)) return Response::json($result);
        if ($result === null)                    return Response::html('');
        return Response::html((string)$result);
    }

    /**
     * @param array<int, class-string> $middlewares
     */
    private function runMiddlewares(array $middlewares, Request $request): ?Response
    {
        foreach ($middlewares as $mwClass) {
            if (!class_exists($mwClass)) {
                throw new HttpException(500, "Middleware inexistente: {$mwClass}");
            }
            $mw = new $mwClass();
            if (!$mw instanceof Middleware) {
                throw new HttpException(500, "Middleware inválido: {$mwClass}");
            }
            $result = $mw->handle($request);
            if ($result instanceof Response) return $result;
        }
        return null;
    }
}
