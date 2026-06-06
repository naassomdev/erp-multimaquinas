<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Helper de integração Vite ↔ PHP.
 *
 *  Modo DEV  → existe `public/hot` (criado pelo plugin do vite.config.js).
 *              Geramos tags apontando pro dev server (HMR ativo).
 *
 *  Modo PROD → lemos `public/build/.vite/manifest.json` e devolvemos as tags
 *              com paths hashados (cache eterno).
 *
 * Uso na view:
 *
 *   <?= Vite::tags(['resources/js/app.js']) ?>
 *
 * Em build, o próprio Vite já inclui o CSS associado (campo `css` do manifest).
 */
final class Vite
{
    private const HOT_FILE      = '/public/hot';
    private const MANIFEST_PATH = '/public/build/.vite/manifest.json';
    private const BUILD_BASE    = '/build';

    /** @var array<string, mixed>|null Cache do manifest carregado uma vez por request. */
    private static ?array $manifestCache = null;

    /**
     * Renderiza as tags HTML para os entry points informados.
     *
     * @param array<int, string> $entries Caminhos relativos dos entries (ex: ['resources/js/app.js'])
     */
    public static function tags(array $entries): string
    {
        if (self::isDev()) {
            return self::devTags($entries);
        }
        return self::prodTags($entries);
    }

    /**
     * Útil em testes/CLI: informa em que modo o helper opera.
     */
    public static function isDev(): bool
    {
        return is_file(BASE_PATH . self::HOT_FILE);
    }

    /**
     * Caminho público final de um asset (já considera dev/prod).
     * Útil pra `<img>`, `<source>`, etc.
     */
    public static function asset(string $entry): string
    {
        if (self::isDev()) {
            return rtrim(self::devUrl(), '/') . '/' . ltrim($entry, '/');
        }
        $manifest = self::manifest();
        $key = ltrim($entry, '/');
        if (!isset($manifest[$key]['file'])) {
            throw new RuntimeException("Vite: entry '{$key}' não existe no manifest. Rode `npm run build`.");
        }
        return self::BUILD_BASE . '/' . ltrim($manifest[$key]['file'], '/');
    }

    // ── Internals ──────────────────────────────────────────────────────

    private static function devUrl(): string
    {
        $url = trim((string) @file_get_contents(BASE_PATH . self::HOT_FILE));
        return $url !== '' ? $url : 'http://localhost:5173';
    }

    /**
     * @param array<int, string> $entries
     */
    private static function devTags(array $entries): string
    {
        $base = rtrim(self::devUrl(), '/');
        $html = sprintf('<script type="module" src="%s/@vite/client"></script>', self::esc($base));

        foreach ($entries as $entry) {
            $html .= sprintf(
                "\n<script type=\"module\" src=\"%s/%s\"></script>",
                self::esc($base),
                self::esc(ltrim($entry, '/'))
            );
        }
        return $html;
    }

    /**
     * @param array<int, string> $entries
     */
    private static function prodTags(array $entries): string
    {
        $manifest  = self::manifest();
        $cssTags   = [];
        $jsTags    = [];
        $seenCss   = [];

        foreach ($entries as $entry) {
            $key = ltrim($entry, '/');
            $row = $manifest[$key] ?? null;

            if ($row === null) {
                throw new RuntimeException(
                    "Vite: entry '{$key}' não encontrado no manifest. "
                    . 'Rode `npm run build` ou verifique o caminho.'
                );
            }

            $file = self::BUILD_BASE . '/' . ltrim($row['file'], '/');

            // Se for entry SCSS, o "file" já é o .css final
            if (str_ends_with($row['file'], '.css')) {
                $cssTags[$file] = sprintf('<link rel="stylesheet" href="%s">', self::esc($file));
                continue;
            }

            // Entry JS: emite <script type="module"> e CSS associado
            $jsTags[] = sprintf('<script type="module" src="%s"></script>', self::esc($file));

            foreach ($row['css'] ?? [] as $css) {
                $cssUrl = self::BUILD_BASE . '/' . ltrim($css, '/');
                if (isset($seenCss[$cssUrl])) continue;
                $seenCss[$cssUrl] = true;
                $cssTags[$cssUrl] = sprintf('<link rel="stylesheet" href="%s">', self::esc($cssUrl));
            }

            // Modulepreload pra chunks que esse entry depende — melhora 1ª pintura
            foreach ($row['imports'] ?? [] as $importKey) {
                if (!isset($manifest[$importKey]['file'])) continue;
                $url = self::BUILD_BASE . '/' . ltrim($manifest[$importKey]['file'], '/');
                $jsTags[] = sprintf('<link rel="modulepreload" href="%s">', self::esc($url));
            }
        }

        // CSS antes de JS para evitar FOUC
        return implode("\n", array_values($cssTags))
             . (!empty($cssTags) && !empty($jsTags) ? "\n" : '')
             . implode("\n", $jsTags);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function manifest(): array
    {
        if (self::$manifestCache !== null) {
            return self::$manifestCache;
        }
        $path = BASE_PATH . self::MANIFEST_PATH;
        if (!is_file($path)) {
            throw new RuntimeException(
                'Vite: manifest.json não encontrado. Rode `npm run build` antes de usar a aplicação.'
            );
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json)) {
            throw new RuntimeException('Vite: manifest.json corrompido.');
        }
        return self::$manifestCache = $json;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
