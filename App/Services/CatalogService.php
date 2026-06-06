<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Bridge entre o ERP (PHP) e o microserviço Node `services/catalog-api`.
 *
 * O Node escuta em 127.0.0.1:3001 (loopback) e expõe scraping das fontes
 * de vista explodida (Felap, TSN, Bosch, Milwaukee). Aqui apenas fazemos
 * o proxy via cURL, com timeout curto e error handling.
 *
 * Endpoints expostos pelo Node:
 *   GET  /api/fontes
 *   GET  /api/marcas?fonte=
 *   GET  /api/modelos?fonte=&marca=&brand=&q=
 *   GET  /api/produto?fonte=&modelo=&typenr=
 *   GET  /api/pdf?marca=&modelo=                  (302 redirect)
 *   DELETE /api/cache
 */
final class CatalogService
{
    private const BASE_URL_DEFAULT = 'http://127.0.0.1:3001';
    private const TIMEOUT_SECONDS  = 20;

    public function __construct(private readonly string $baseUrl = self::BASE_URL_DEFAULT) {}

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query(array_filter(
                $query,
                static fn($v) => $v !== null && $v !== ''
            ));
        }

        return $this->request('GET', $url);
    }

    /**
     * Para o `GET /api/pdf` que devolve um redirect 302 para o PDF na origem.
     * Devolvemos a URL final (não o conteúdo do PDF) para o frontend abrir.
     */
    public function resolvePdfRedirect(int $marcaId, string $arquivo): ?string
    {
        if (!function_exists('curl_init')) return null;

        $url = rtrim($this->baseUrl, '/') . '/api/pdf?'
             . http_build_query(['marca' => $marcaId, 'modelo' => $arquivo]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close() é no-op desde PHP 8.0 e deprecated em 8.5 — não chamamos
        // (o handle é liberado pelo destrutor automaticamente).

        if ($resp === false) return null;
        if ($code === 302 && preg_match('/^Location:\s*(\S+)/im', (string) $resp, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Healthcheck — útil pra UI mostrar "API offline" sem dar 500.
     */
    public function isAlive(): bool
    {
        try {
            $r = $this->request('GET', rtrim($this->baseUrl, '/') . '/health', timeout: 3);
            return ($r['ok'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Limpa o cache do microserviço (útil quando uma fonte mudou de layout).
     */
    public function clearCache(): bool
    {
        try {
            $r = $this->request('DELETE', rtrim($this->baseUrl, '/') . '/api/cache');
            return ($r['ok'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Internal ────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, int $timeout = self::TIMEOUT_SECONDS): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'Extensão PHP "curl" ausente. Instale-a no aaPanel: '
                . 'App Store → PHP X.X → Settings → Install Extension → curl'
            );
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Multimaquinas-ERP/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        // curl_close() é no-op desde PHP 8.0 e deprecated em 8.5 — não chamamos
        // (o handle é liberado pelo destrutor automaticamente).

        if ($body === false) {
            throw new RuntimeException("Catalog API indisponível: {$err}");
        }
        if ($code >= 500) {
            throw new RuntimeException("Catalog API HTTP {$code}");
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Catalog API: resposta JSON inválida');
        }
        // Mesmo em 4xx, devolvemos o JSON pro controller decidir o status
        // (o Node usa { ok:false, erro:'...' } com 4xx — info útil pra UI).
        $data['_http_status'] = $code;
        return $data;
    }
}
