<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Application
{
    private array $config = [];
    private ?Request $currentRequest = null;

    public function __construct(private readonly string $basePath)
    {
        $this->loadConfig();
        $this->configureRuntime();
    }

    public function run(): void
    {
        try {
            $routes = require $this->basePath . '/config/routes.php';
            $router = new Router($routes);
            $request = Request::capture();
            $this->currentRequest = $request;
            $response = $router->dispatch($request);
            $response->send();
        } catch (Throwable $e) {
            $this->renderException($e);
        }
    }

    public function config(string $section, ?string $key = null): mixed
    {
        if ($key === null) return $this->config[$section] ?? null;
        return $this->config[$section][$key] ?? null;
    }

    private function loadConfig(): void
    {
        $this->config['app']      = require $this->basePath . '/config/app.php';
        $this->config['database'] = require $this->basePath . '/config/database.php';
    }

    private function configureRuntime(): void
    {
        date_default_timezone_set($this->config['app']['timezone']);

        $debug = $this->config['app']['debug'];
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('display_startup_errors', $debug ? '1' : '0');
        error_reporting(E_ALL);

        if (PHP_SESSION_NONE === session_status()) {
            session_name($this->config['app']['session']['name']);
            session_set_cookie_params([
                'lifetime' => $this->config['app']['session']['lifetime'],
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    private function renderException(Throwable $e): void
    {
        $code = $e instanceof HttpException ? $e->getStatusCode() : 500;
        if ($code < 400 || $code > 599) $code = 500;

        if (!headers_sent()) http_response_code($code);

        $debug = $this->config['app']['debug'] ?? false;
        $expectsJson = $this->currentRequest?->wantsJson() === true
            || str_starts_with($this->currentRequest?->path ?? '', '/api/');

        if ($expectsJson) {
            header('Content-Type: application/json; charset=utf-8');

            if ($debug) {
                echo json_encode([
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }

            echo json_encode([
                'ok' => false,
                'error' => $code === 404 ? 'Recurso não encontrado.' : 'Algo deu errado. Tente novamente em instantes.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo View::render('errors/http', [
            'statusCode' => $code,
            'title' => $this->errorTitle($code),
            'message' => $this->errorMessage($code, $e, $debug),
            'backUrl' => $this->backUrl(),
        ], '');
    }

    private function errorTitle(int $code): string
    {
        return match ($code) {
            403 => 'Acesso negado',
            404 => 'Página não encontrada',
            default => 'Erro no sistema',
        };
    }

    private function errorMessage(int $code, Throwable $e, bool $debug): string
    {
        if ($code === 403) {
            return 'Você não tem permissão para acessar este recurso.';
        }

        if ($code === 404) {
            return 'O recurso solicitado não foi encontrado.';
        }

        if ($debug && $e instanceof HttpException && $code < 500) {
            return trim($e->getMessage()) !== '' ? $e->getMessage() : 'Não foi possível concluir a solicitação.';
        }

        return 'Algo deu errado. Tente novamente em instantes.';
    }

    private function backUrl(): string
    {
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            return $referer;
        }

        return Auth::check() ? '/dashboard' : '/login';
    }
}
