<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array  $query,
        public readonly array  $body,
        public readonly array  $server,
        public readonly array  $files = [],
    ) {}

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');
        if ($path === '/') $path = '/';

        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJson = stripos($contentType, 'application/json') !== false;

        if ($isJson) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        } elseif (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)
                  && stripos($contentType, 'multipart/form-data') === false) {
            $raw = file_get_contents('php://input') ?: '';
            parse_str($raw, $body);
        }

        return new self($method, $path, $_GET, $body, $_SERVER, $_FILES);
    }

    /**
     * @return array{name:string, type:string, tmp_name:string, error:int, size:int}|null
     */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['tmp_name']) || !is_string($f['tmp_name'])) {
            return null;
        }
        return $f;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function wantsJson(): bool
    {
        $accept = $this->server['HTTP_ACCEPT'] ?? '';
        return stripos($accept, 'application/json') !== false;
    }
}
